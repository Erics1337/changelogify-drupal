<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\changelogify_ai\PromptTemplateRegistry;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\SynthesisBatcher;
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
 * Tests durable hierarchical synthesis state and queue orchestration.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisJobManagerTest extends TestCase {

  /**
   * Large jobs recurse through bounded intermediate rounds to one final result.
   */
  public function testCompletesRecursiveSynthesisWithReferenceOnlyQueueItems(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer);
    $jobId = $manager->start(
      $this->evidence(250),
      'public_product',
      SynthesisContract::PRESET_SHORT,
      PromptTemplateRegistry::VERSION,
      'policy-1',
      'eligibility-1',
    );
    foreach ($queueItems as $item) {
      self::assertSame(['job_id', 'batch_id'], array_keys($item));
    }
    $this->drain($manager, $queueItems);

    $job = $manager->get($jobId);
    self::assertSame('completed', $job['status']);
    self::assertGreaterThan(1, $job['round']);
    self::assertSame($job['total_batches'], $job['completed_batches']);
    self::assertArrayNotHasKey('rounds', $job);
    self::assertArrayNotHasKey('instructions', $job);
    self::assertLessThanOrEqual(5, count($manager->result($jobId)->items));
    foreach ($manager->result($jobId)->items as $item) {
      self::assertSame([], array_filter($item->sourceIds, static fn (string $id): bool => str_starts_with($id, 'candidate-')));
    }
    $coverage = $manager->provenance($jobId)['coverage'];
    self::assertSame(250, $coverage['evidence_considered']);
    self::assertSame(250, $coverage['evidence_cited']);
    self::assertSame(0, $coverage['eligible_not_surfaced']);
    self::assertContains(SynthesisContract::STAGE_INTERMEDIATE, array_column($summarizer->calls, 'stage'));
    self::assertContains(SynthesisContract::STAGE_FINAL, array_column($summarizer->calls, 'stage'));
    foreach ($summarizer->calls as $call) {
      self::assertLessThanOrEqual(SynthesisBatcher::MAX_ITEMS, $call['count']);
      self::assertLessThanOrEqual(SynthesisBatcher::MAX_BYTES, $call['bytes']);
      self::assertFalse($call['internal_metadata']);
    }
    $summaries = $manager->all();
    self::assertArrayNotHasKey('final_result', $summaries[$jobId]);
    self::assertArrayNotHasKey('rounds', $summaries[$jobId]);
  }

  /**
   * Thousands of source documents recurse within every request bound.
   */
  public function testThousandsOfEvidenceDocumentsRemainBounded(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer);
    $jobId = $manager->start(
      $this->evidence(2500),
      'public_product',
      SynthesisContract::PRESET_DETAILED,
      PromptTemplateRegistry::VERSION,
      'policy-scale',
      'eligibility-scale',
    );
    $this->drain($manager, $queueItems);

    $job = $manager->get($jobId);
    self::assertSame('completed', $job['status']);
    self::assertGreaterThan(3, $job['round']);
    self::assertSame(2500, $job['coverage']['evidence_considered']);
    self::assertSame(2500, $job['coverage']['evidence_cited']);
    self::assertLessThanOrEqual(25, count($manager->result($jobId)->items));
    foreach ($summarizer->calls as $call) {
      self::assertLessThanOrEqual(SynthesisBatcher::MAX_ITEMS, $call['count']);
      self::assertLessThanOrEqual(SynthesisBatcher::MAX_BYTES, $call['bytes']);
    }
  }

  /**
   * A cancelled job does not block a deliberate identical submission.
   */
  public function testJobAndBatchIdentityAreDeterministic(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer);
    $evidence = $this->evidence(150);
    $firstId = $manager->start($evidence, 'concise', 'standard', '1', 'policy', 'eligibility');
    $firstReferences = $queueItems->getArrayCopy();
    $manager->cancel($firstId);
    $queueItems->exchangeArray([]);
    $secondId = $manager->start($evidence, 'concise', 'standard', '1', 'policy', 'eligibility');
    self::assertNotSame($firstId, $secondId);
    self::assertCount(count($firstReferences), $queueItems->getArrayCopy());
    self::assertSame($secondId, $queueItems[0]['job_id']);
  }

  /**
   * An active matching submission is reused without adding queue work.
   */
  public function testActiveSubmissionKeyIsReused(): void {
    [$manager, , $queueItems] = $this->manager($this->recordingSummarizer());
    $first = $manager->startResult(
      $this->evidence(2),
      'concise',
      'standard',
      '1',
      'policy',
      'eligibility',
      actor: 7,
      submissionKey: hash('sha256', 'stable-submission'),
    );
    $queued = count($queueItems);
    $second = $manager->startResult(
      $this->evidence(2),
      'concise',
      'standard',
      '1',
      'policy',
      'eligibility',
      actor: 7,
      submissionKey: hash('sha256', 'stable-submission'),
    );

    self::assertFalse($first->reused);
    self::assertTrue($second->reused);
    self::assertSame($first->jobId, $second->jobId);
    self::assertCount($queued, $queueItems);
  }

  /**
   * Cancellation removes temporary evidence and makes queued references inert.
   */
  public function testCancellationCleansTemporaryStateAndSkipsProvider(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer);
    $jobId = $manager->start($this->evidence(150), 'concise', 'standard', '1', 'policy', 'eligibility');
    $reference = $queueItems[0];
    $manager->cancel($jobId);
    $manager->process($reference['job_id'], $reference['batch_id']);
    $job = $manager->get($jobId);
    self::assertSame('cancelled', $job['status']);
    self::assertArrayNotHasKey('rounds', $job);
    self::assertSame([], $summarizer->calls);
  }

  /**
   * Transient failures retry twice and then complete without duplicate output.
   */
  public function testTransientFailuresRetryWithinBound(): void {
    $summarizer = $this->recordingSummarizer(2);
    [$manager, , $queueItems] = $this->manager($summarizer);
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $this->drain($manager, $queueItems);
    $job = $manager->get($jobId);
    self::assertSame('completed', $job['status']);
    self::assertSame(2, $job['retry_count']);
    self::assertCount(3, $summarizer->calls);
    self::assertCount(1, $manager->result($jobId)->items);
  }

  /**
   * Exhausted transient failures retain diagnostics but remove evidence.
   */
  public function testTerminalFailureCleansTemporaryState(): void {
    $summarizer = $this->recordingSummarizer(3);
    [$manager, , $queueItems] = $this->manager($summarizer);
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $this->drain($manager, $queueItems);
    $job = $manager->get($jobId);
    self::assertSame('failed', $job['status']);
    self::assertSame(3, $job['retry_count']);
    self::assertArrayHasKey('error_code', $job);
    self::assertArrayNotHasKey('rounds', $job);
    self::assertArrayNotHasKey('instructions', $job);
  }

  /**
   * Duplicate delivery of one completed reference is idempotent.
   */
  public function testDuplicateQueueDeliveryDoesNotRepeatProviderCall(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer);
    $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $reference = $queueItems[0];
    $manager->process($reference['job_id'], $reference['batch_id']);
    $manager->process($reference['job_id'], $reference['batch_id']);
    self::assertCount(1, $summarizer->calls);
  }

  /**
   * A concurrent worker holding the job lock prevents duplicate provider use.
   */
  public function testConcurrentWorkerCannotProcessSameJob(): void {
    $summarizer = $this->recordingSummarizer();
    [$manager, , $queueItems] = $this->manager($summarizer, FALSE);
    $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $reference = $queueItems[0];
    $manager->process($reference['job_id'], $reference['batch_id']);
    self::assertSame([], $summarizer->calls);
    self::assertSame('queued', $manager->get($reference['job_id'])['status']);
  }

  /**
   * Terminal cleanup retains neither sensitive evidence nor provider errors.
   */
  public function testTerminalStateAndQueueReferencesDoNotRetainSensitivePayload(): void {
    $summarizer = $this->recordingSummarizer(3);
    [$manager, $records, $queueItems] = $this->manager($summarizer);
    $evidence = $this->evidence(1);
    $evidence['change-1']['summary'] = 'credential-sensitive-value';
    $jobId = $manager->start(
      $evidence,
      'concise',
      'short',
      '1',
      'policy',
      'eligibility',
      'temporary-credential-sensitive-instruction',
    );
    self::assertStringNotContainsString('credential-sensitive-value', json_encode($queueItems, JSON_THROW_ON_ERROR));
    $this->drain($manager, $queueItems);
    self::assertSame('failed', $manager->get($jobId)['status']);
    self::assertStringNotContainsString('credential-sensitive-value', json_encode($records, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('temporary-credential-sensitive-instruction', json_encode($records, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('Deterministic transient failure', json_encode($records, JSON_THROW_ON_ERROR));
  }

  /**
   * Refusal, malformed, empty, and timeout results create no usable output.
   */
  #[DataProvider('unsafeProviderModeProvider')]
  public function testUnsafeProviderModesFailWithoutProvenance(string $mode, int $retries): void {
    [$manager, , $queueItems] = $this->manager(new FakeSummarizer($mode));
    $jobId = $manager->start($this->evidence(1), 'concise', 'short', '1', 'policy', 'eligibility');
    $this->drain($manager, $queueItems);
    $job = $manager->get($jobId);
    self::assertSame('failed', $job['status']);
    self::assertSame($retries, $job['retry_count']);
    self::assertArrayNotHasKey('final_result', $job);
    self::assertArrayNotHasKey('provenance', $job);
    self::assertArrayNotHasKey('rounds', $job);
  }

  /**
   * Provides deterministic unsafe or unavailable provider behaviors.
   */
  public static function unsafeProviderModeProvider(): array {
    return [
      'refusal' => ['refusal', 0],
      'malformed output' => ['malformed', 0],
      'empty output' => ['empty', 0],
      'timeout' => ['timeout', 3],
    ];
  }

  /**
   * Creates a recording summarizer with optional transient failures.
   */
  private function recordingSummarizer(int $failures = 0): object {
    return new class($failures) implements SummarizerInterface {

      /** Recorded bounded requests. */
      public array $calls = [];

      public function __construct(private int $failures) {}

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
          'bytes' => strlen(json_encode($request->evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
          'ids' => array_keys($request->evidence),
          'internal_metadata' => array_filter(
            $request->evidence,
            static fn (array $document): bool => isset($document['job_id']) || isset($document['original_source_ids']),
          ) !== [],
        ];
        if ($this->failures > 0) {
          $this->failures--;
          throw new TransientSummarizationException('Deterministic transient failure.');
        }
        return (new FakeSummarizer())->summarize($request);
      }

    };
  }

  /**
   * Creates a manager with in-memory durable state and queue references.
   *
   * @return array{SynthesisJobManager, \ArrayObject, \ArrayObject}
   *   Manager, job records, and queued reference payloads.
   */
  private function manager(SummarizerInterface $summarizer, bool $lockAcquired = TRUE): array {
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
    $queueItems = new \ArrayObject();
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(static function (mixed $item) use ($queueItems): int {
      $queueItems[] = $item;
      return count($queueItems);
    });
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with(SynthesisJobManager::QUEUE_NAME)->willReturn($queue);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($lockAcquired);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    return [new SynthesisJobManager(
      $summarizer,
      new ResultValidator(),
      new SynthesisBatcher(),
      new SynthesisProvenanceResolver(),
      $keyValue,
      $lock,
      $queueFactory,
      $time,
      $this->createMock(LoggerInterface::class),
      ), $records, $queueItems,
    ];
  }

  /**
   * Drains safe references created during every recursive round.
   */
  private function drain(SynthesisJobManager $manager, \ArrayObject $queueItems): void {
    $iterations = 0;
    while (count($queueItems) > 0) {
      self::assertLessThan(100, $iterations++);
      $items = $queueItems->getArrayCopy();
      $reference = array_shift($items);
      $queueItems->exchangeArray($items);
      $manager->process($reference['job_id'], $reference['batch_id']);
    }
  }

  /**
   * Creates deterministic, provider-safe evidence documents.
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
