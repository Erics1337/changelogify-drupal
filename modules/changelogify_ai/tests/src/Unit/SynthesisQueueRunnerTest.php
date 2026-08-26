<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use Drupal\changelogify_ai\AiQueueHealth;
use Drupal\changelogify_ai\SynthesisJobManager;
use Drupal\changelogify_ai\SynthesisQueueRunner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests the bounded synthesis-only queue runner.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisQueueRunnerTest extends UnitTestCase {

  /**
   * Successful references are processed and deleted.
   */
  public function testSuccessfulReferenceIsDeleted(): void {
    $item = (object) ['data' => ['job_id' => 'job-1']];
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::once())->method('createQueue');
    $queue->expects(self::exactly(2))->method('claimItem')->with(120)
      ->willReturnOnConsecutiveCalls($item, FALSE);
    $queue->expects(self::once())->method('deleteItem')->with($item);
    $queue->expects(self::never())->method('releaseItem');
    $queue->method('numberOfItems')->willReturn(0);

    $worker = $this->createMock(QueueWorkerInterface::class);
    $worker->expects(self::once())->method('processItem')->with($item->data);
    $runner = $this->runner($queue, $worker, [1.0, 1.1, 1.2, 1.3]);

    $summary = $runner->run();
    self::assertSame(1, $summary['attempted']);
    self::assertSame(1, $summary['completed']);
    self::assertSame(0, $summary['failed']);
    self::assertSame(0, $summary['remaining']);
  }

  /**
   * Retry and queue-suspension signals preserve their Drupal queue semantics.
   */
  public function testRequeueAndSuspensionReleaseReferences(): void {
    $retry = (object) ['data' => 'retry'];
    $stop = (object) ['data' => 'stop'];
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects(self::once())->method('createQueue');
    $queue->expects(self::exactly(2))->method('claimItem')->with(120)
      ->willReturnOnConsecutiveCalls($retry, $stop);
    $queue->expects(self::exactly(2))->method('releaseItem')
      ->with(self::logicalOr($retry, $stop));
    $queue->expects(self::never())->method('deleteItem');
    $queue->method('numberOfItems')->willReturn(2);

    $worker = $this->createMock(QueueWorkerInterface::class);
    $worker->expects(self::exactly(2))->method('processItem')
      ->willReturnCallback(static function (string $data): never {
        if ($data === 'retry') {
          throw new RequeueException();
        }
        throw new SuspendQueueException();
      });
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('warning');
    $runner = $this->runner($queue, $worker, [1.0, 1.1, 1.2, 1.3], $logger);

    $summary = $runner->run();
    self::assertSame(2, $summary['attempted']);
    self::assertSame(1, $summary['requeued']);
    self::assertTrue($summary['suspended']);
    self::assertSame(2, $summary['remaining']);
  }

  /**
   * Creates a runner with deterministic queue and time dependencies.
   */
  private function runner(QueueInterface $queue, QueueWorkerInterface $worker, array $clockValues, ?LoggerInterface $logger = NULL): SynthesisQueueRunner {
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects(self::once())->method('get')
      ->with(SynthesisJobManager::QUEUE_NAME)
      ->willReturn($queue);
    $workerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $workerManager->expects(self::once())->method('createInstance')
      ->with(SynthesisJobManager::QUEUE_NAME)
      ->willReturn($worker);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentMicroTime')->willReturnOnConsecutiveCalls(...$clockValues);
    $time->method('getRequestTime')->willReturn(1000);
    $state = $this->createMock(StateInterface::class);
    $state->expects(self::exactly(3))->method('set')
      ->with('changelogify_ai.last_synthesis_runner', 1000);
    $health = new AiQueueHealth(
      $state,
      $this->createMock(Connection::class),
      $time,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
    );
    return new SynthesisQueueRunner(
      $queueFactory,
      $workerManager,
      $health,
      $time,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
