<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests operation states and privacy-bounded diagnostic persistence.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class AiOperationManagerTest extends TestCase {

  /**
   * Tests completed idempotency keys cannot create a second mutation.
   */
  public function testCompletedOperationIsIdempotent(): void {
    [$manager] = $this->manager();
    $result = $manager->execute($this->request(), ['change-1']);
    self::assertSame('completed', $result->status);
    $this->expectException(\RuntimeException::class);
    $manager->execute($this->request(), ['change-1']);
  }

  /**
   * A concurrent lock owner prevents a second execution before provider use.
   */
  public function testLockContentionPreventsDuplicateExecution(): void {
    [$manager] = $this->manager([], FALSE);
    $this->expectException(\RuntimeException::class);
    $manager->execute($this->request(), ['change-1']);
  }

  /**
   * A stale running record can resume after its abandoned lock expires.
   */
  public function testStaleRunningOperationCanResume(): void {
    [$manager, $records] = $this->manager([
      'test-key' => ['status' => 'running', 'created' => 1],
    ]);
    $result = $manager->execute($this->request(), ['change-1']);
    self::assertSame('completed', $result->status);
    self::assertSame('completed', $records['test-key']['status']);
  }

  /**
   * Corrupt key-value entries do not break the diagnostic history page.
   */
  public function testAllIgnoresNonArrayRecords(): void {
    [$manager] = $this->manager([
      'valid' => ['status' => 'completed', 'created' => 10],
      'corrupt' => 'not-an-operation',
    ]);
    self::assertSame(['valid'], array_keys($manager->all()));
  }

  /**
   * Tests queued diagnostics retain only a hash, never outbound evidence.
   */
  public function testQueuedOperationIsCredentialFreeAndCancellable(): void {
    [$manager, $records] = $this->manager();
    $manager->enqueue($this->request(), ['change-1']);
    self::assertSame('queued', $records['test-key']['status']);
    self::assertArrayHasKey('payload_hash', $records['test-key']);
    self::assertArrayNotHasKey('evidence', $records['test-key']);
    self::assertArrayNotHasKey('authorization', $records['test-key']);
    $manager->cancel('test-key');
    self::assertSame('cancelled', $records['test-key']['status']);
  }

  /**
   * Synthesis diagnostics retain the contract without retaining evidence.
   */
  public function testSynthesisOperationRetainsContractIdentity(): void {
    [$manager, $records] = $this->manager();
    $manager->execute($this->synthesisRequest(), ['change-1']);
    self::assertSame(SynthesisContract::VERSION, $records['synthesis-key']['synthesis_version']);
    self::assertSame(SynthesisContract::STAGE_FINAL, $records['synthesis-key']['synthesis_stage']);
    self::assertSame(SynthesisContract::PRESET_SHORT, $records['synthesis-key']['length_preset']);
    self::assertArrayNotHasKey('evidence', $records['synthesis-key']);
  }

  /**
   * Tests retention deletes only expired operation diagnostics.
   */
  public function testPurgeDeletesOnlyExpiredHistory(): void {
    [$manager, $records] = $this->manager([
      'expired' => ['created' => 1],
      'recent' => ['created' => 99999],
    ]);
    $manager->purge(1);
    self::assertArrayNotHasKey('expired', $records);
    self::assertArrayHasKey('recent', $records);
  }

  /**
   * Dispositions correlate reviewed output without retaining generated text.
   */
  public function testDispositionRecordsAcceptedRevisionWithoutGeneratedText(): void {
    [$manager, $records] = $this->manager();
    $result = $manager->execute($this->request(), ['change-1'], 7, 4);
    $manager->recordDisposition((string) $result->operationId, 'accepted', 5);
    self::assertSame('accepted', $records['test-key']['disposition']);
    self::assertSame(5, $records['test-key']['accepted_revision_id']);
    self::assertArrayNotHasKey('generated_text', $records['test-key']);
  }

  /**
   * Provider error text is neither retained nor sent to Drupal logging.
   */
  public function testFailureStoresOnlyExceptionClass(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(
      self::callback(static fn (string $message): bool => !str_contains($message, 'Transient fake provider failure.')),
      self::callback(static fn (array $context): bool => !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Transient fake provider failure.')),
    );
    [$manager, $records] = $this->manager([], TRUE, 'failure', $logger);
    try {
      $manager->execute($this->request(), ['change-1']);
      self::fail('Expected the fake provider to fail.');
    }
    catch (\Throwable) {
      self::assertSame('failed', $records['test-key']['status']);
      self::assertArrayHasKey('error_class', $records['test-key']);
      self::assertSame('provider_failure', $records['test-key']['error_code']);
      self::assertArrayNotHasKey('error', $records['test-key']);
    }
  }

  /**
   * Creates a fully deterministic operation manager and its backing records.
   *
   * @param array<string, array<string, mixed>> $initialRecords
   *   Initial operation records.
   * @param bool $lockAcquired
   *   Whether the simulated concurrent lock is available.
   * @param string $providerMode
   *   Deterministic fake provider behavior.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger double.
   *
   * @return array{AiOperationManager, \ArrayObject<string, array<string, mixed>>}
   *   Manager and by-reference store contents.
   */
  private function manager(array $initialRecords = [], bool $lockAcquired = TRUE, string $providerMode = 'success', ?LoggerInterface $logger = NULL): array {
    $records = new \ArrayObject($initialRecords);
    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->willReturnCallback(static function (string $key) use ($records): mixed {
      return $records[$key] ?? NULL;
    });
    $store->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($records): void {
      $records[$key] = $value;
    });
    $store->method('delete')->willReturnCallback(static function (string $key) use ($records): void {
      unset($records[$key]);
    });
    $store->method('getAll')->willReturnCallback(static function () use ($records): array {
      return $records->getArrayCopy();
    });
    $keyValue = $this->createMock(KeyValueFactoryInterface::class);
    $keyValue->method('get')->with('changelogify_ai.operations')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($lockAcquired);
    $lock->method('lockMayBeAvailable')->willReturn(TRUE);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(1);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100000);
    $queue = $this->createMock(QueueInterface::class);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);
    $logger ??= $this->createMock(LoggerInterface::class);
    return [new AiOperationManager(
      new FakeSummarizer($providerMode),
      new ResultValidator(),
      $keyValue,
      $lock,
      $account,
      $time,
      $logger,
      $queueFactory,
      ), $records,
    ];
  }

  /**
   * Creates a minimum redacted generation request.
   */
  private function request(): SummarizationRequest {
    return new SummarizationRequest(
      'complete_draft',
      'concise',
      ['change-1' => ['section' => 'changed', 'summary' => 'Safe evidence.']],
      '1',
      '1',
      'test-key',
    );
  }

  /**
   * Creates a minimum versioned synthesis request.
   */
  private function synthesisRequest(): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      'concise',
      ['change-1' => ['section' => 'changed', 'summary' => 'Safe evidence.']],
      '1',
      '1',
      'synthesis-key',
      '',
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_SHORT,
    );
  }

}
