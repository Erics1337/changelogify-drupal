<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\changelogify_ai\PromptTemplateRegistry;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\SynthesisProvenanceResolver;
use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizerInterface;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests durable single-request synthesis orchestration.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisJobManagerTest extends TestCase {

  /**
   * Every reviewed evidence document reaches one final provider request.
   */
  public function testSendsThousandsOfEvidenceDocumentsInOneRequest(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager] = $this->manager($summarizer);
    $evidence = $this->evidence(2500);
    $jobId = $manager->start(
      $evidence,
      'public_product',
      SynthesisContract::PRESET_AUTO,
      PromptTemplateRegistry::VERSION,
      'policy-scale',
      'eligibility-scale',
    );
    $manager->process($jobId);

    $job = $manager->get($jobId);
    self::assertSame('completed', $job['status']);
    self::assertSame(1, $job['attempt_count']);
    self::assertSame(0, $job['retry_count']);
    self::assertCount(1, $summarizer->calls);
    self::assertSame(SynthesisContract::STAGE_FINAL, $summarizer->calls[0]['stage']);
    self::assertSame(2500, $summarizer->calls[0]['count']);
    self::assertSame(array_keys($evidence), $summarizer->calls[0]['ids']);
    self::assertLessThanOrEqual(25, count($manager->result($jobId)->items));
    self::assertSame(2500, $job['coverage']['evidence_considered']);
    self::assertSame(2500, $job['coverage']['evidence_cited']);
    self::assertSame(0, $job['coverage']['eligible_not_surfaced']);
    self::assertArrayNotHasKey('evidence', $job);
    self::assertArrayNotHasKey('instructions', $job);
  }

  /**
   * Matching active submissions reuse the same prepared operation.
   */
  public function testActiveSubmissionKeyIsReused(): void {
    [$manager] = $this->manager($this->recordingSummarizer());
    $key = hash('sha256', 'stable-submission');
    $first = $manager->startResult(
      $this->evidence(2), 'concise', 'standard', '1', 'policy', 'eligibility',
      actor: 7, submissionKey: $key,
    );
    $second = $manager->startResult(
      $this->evidence(2), 'concise', 'standard', '1', 'policy', 'eligibility',
      actor: 7, submissionKey: $key,
    );

    self::assertFalse($first->reused);
    self::assertTrue($second->reused);
    self::assertSame($first->jobId, $second->jobId);
  }

  /**
   * Cancelled prepared work never reaches the provider.
   */
  public function testCancellationCleansTemporaryStateAndSkipsProvider(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager] = $this->manager($summarizer);
    $jobId = $manager->start(
      $this->evidence(2), 'concise', 'standard', '1', 'policy', 'eligibility',
      'temporary instruction',
    );
    $manager->cancel($jobId);
    $manager->process($jobId);

    $job = $manager->get($jobId);
    self::assertSame('cancelled', $job['status']);
    self::assertArrayNotHasKey('evidence', $job);
    self::assertArrayNotHasKey('instructions', $job);
    self::assertCount(0, $summarizer->calls);
  }

  /**
   * A transient provider failure is terminal and is not retried implicitly.
   */
  public function testProviderFailureMakesExactlyOneCall(): void {
    $summarizer = $this->recordingSummarizer(TRUE);
    [$manager, $records] = $this->manager($summarizer);
    $evidence = $this->evidence(1);
    $evidence['change-1']['summary'] = 'credential-sensitive-value';
    $jobId = $manager->start(
      $evidence, 'concise', 'short', '1', 'policy', 'eligibility',
      'credential-sensitive-instruction',
    );
    $manager->process($jobId);

    $job = $manager->get($jobId);
    self::assertSame('failed', $job['status']);
    self::assertSame(1, $job['attempt_count']);
    self::assertSame(0, $job['retry_count']);
    self::assertCount(1, $summarizer->calls);
    self::assertArrayHasKey('error_code', $job);
    self::assertStringNotContainsString('credential-sensitive-value', json_encode($records, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('credential-sensitive-instruction', json_encode($records, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('Deterministic transient failure', json_encode($records, JSON_THROW_ON_ERROR));
  }

  /**
   * Reprocessing a terminal job cannot duplicate the provider request.
   */
  public function testTerminalJobIsIdempotent(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager] = $this->manager($summarizer);
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $manager->process($jobId);
    $manager->process($jobId);

    self::assertCount(1, $summarizer->calls);
  }

  /**
   * A held operation lock prevents a duplicate request.
   */
  public function testHeldLockSkipsProviderRequest(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager] = $this->manager($summarizer, FALSE);
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $manager->process($jobId);

    self::assertSame('prepared', $manager->get($jobId)['status']);
    self::assertCount(0, $summarizer->calls);
  }

  /**
   * Unsafe provider outcomes create no usable synthesis result.
   */
  #[DataProvider('unsafeProviderModeProvider')]
  public function testUnsafeProviderModesFailWithoutProvenance(string $mode): void {
    [$manager] = $this->manager(new FakeSummarizer($mode));
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $manager->process($jobId);

    $job = $manager->get($jobId);
    self::assertSame('failed', $job['status']);
    self::assertSame(1, $job['attempt_count']);
    self::assertSame(0, $job['retry_count']);
    self::assertArrayNotHasKey('final_result', $job);
    self::assertArrayNotHasKey('provenance', $job);
  }

  /**
   * Provides deterministic unsafe provider behaviors.
   */
  public static function unsafeProviderModeProvider(): array {
    return [
      'refusal' => ['refusal'],
      'malformed output' => ['malformed'],
      'empty output' => ['empty'],
      'timeout' => ['timeout'],
    ];
  }

  /**
   * Creates a recording summarizer that can fail its one request.
   */
  private function recordingSummarizer(bool $fail = FALSE): object {
    return new class($fail) implements SummarizerInterface {

      /** Recorded requests. */
      public array $calls = [];

      public function __construct(private readonly bool $fail) {}

      /** {@inheritdoc} */
      public function isAvailable(): bool {
        return TRUE;
      }

      /** {@inheritdoc} */
      public function selectedProviderModel(): ?array {
        return ['provider' => 'fake', 'model' => 'deterministic'];
      }

      /** {@inheritdoc} */
      public function summarize(SummarizationRequest $request): SummarizationResult {
        $this->calls[] = [
          'stage' => $request->getSynthesisStage(),
          'count' => count($request->evidence),
          'ids' => array_keys($request->evidence),
        ];
        if ($this->fail) {
          throw new TransientSummarizationException('Deterministic transient failure.');
        }
        return (new FakeSummarizer())->summarize($request);
      }

    };
  }

  /**
   * Creates a manager with in-memory durable state.
   *
   * @return array{SynthesisJobManager, \ArrayObject<string, array<string, mixed>>}
   *   Manager and job records.
   */
  private function manager(SummarizerInterface $summarizer, bool $jobLockAcquired = TRUE): array {
    $records = new \ArrayObject();
    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnCallback(static fn (string $key): mixed => $records[$key] ?? NULL);
    $store->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($records): void {
      $records[$key] = $value;
    });
    $store->method('delete')->willReturnCallback(static function (string $key) use ($records): void {
      unset($records[$key]);
    });
    $store->method('getAll')->willReturnCallback(static fn (): array => $records->getArrayCopy());
    $keyValue = $this->createMock(KeyValueFactoryInterface::class);
    $keyValue->method('get')->with('changelogify_ai.synthesis_jobs')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(
      static fn (string $name): bool => str_starts_with($name, 'changelogify_ai:synthesis_submission:') || $jobLockAcquired,
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    return [new SynthesisJobManager(
      $summarizer,
      new ResultValidator(),
      new SynthesisProvenanceResolver(),
      $keyValue,
      $lock,
      $time,
      $this->createMock(LoggerInterface::class),
      ), $records,
    ];
  }

  /**
   * Creates deterministic provider-safe evidence documents.
   */
  private function evidence(int $count): array {
    $evidence = [];
    for ($index = 1; $index <= $count; $index++) {
      $id = "change-{$index}";
      $evidence[$id] = [
        'id' => $id,
        'section' => 'changed',
        'summary' => "Recorded change {$index}.",
      ];
    }
    return $evidence;
  }

}
