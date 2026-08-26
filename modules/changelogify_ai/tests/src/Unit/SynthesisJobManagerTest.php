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
   * Identical inputs produce the same job and batch identities.
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
    self::assertSame($firstId, $secondId);
    self::assertSame($firstReferences, $queueItems->getArrayCopy());
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
  private function manager(SummarizerInterface $summarizer): array {
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
    $lock->method('acquire')->willReturn(TRUE);
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
