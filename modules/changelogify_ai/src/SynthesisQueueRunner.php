<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;

/**
 * Runs only Changelogify synthesis work in a bounded background process.
 */
final class SynthesisQueueRunner {

  public function __construct(private readonly QueueFactory $queueFactory, private readonly QueueWorkerManagerInterface $workerManager, private readonly AiQueueHealth $queueHealth, private readonly TimeInterface $time, private readonly LoggerInterface $logger) {}

  /**
   * Processes queued synthesis references within explicit operational bounds.
   */
  public function run(int $timeLimit = 55, int $itemLimit = 0, int $leaseTime = 120): array {
    $timeLimit = min(3600, max(1, $timeLimit));
    $itemLimit = min(10000, max(0, $itemLimit));
    $leaseTime = min(3600, max(30, $leaseTime));
    $started = $this->time->getCurrentMicroTime();
    $deadline = $started + $timeLimit;
    $queue = $this->queueFactory->get(SynthesisJobManager::QUEUE_NAME);
    $queue->createQueue();
    $worker = $this->workerManager->createInstance(SynthesisJobManager::QUEUE_NAME);
    $summary = [
      'attempted' => 0,
      'completed' => 0,
      'requeued' => 0,
      'deferred' => 0,
      'failed' => 0,
      'suspended' => FALSE,
    ];
    $this->queueHealth->recordRunner();

    while ($this->time->getCurrentMicroTime() < $deadline
      && ($itemLimit === 0 || $summary['attempted'] < $itemLimit)
      && ($item = $queue->claimItem($leaseTime))) {
      $summary['attempted']++;
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $summary['completed']++;
      }
      catch (DelayedRequeueException $exception) {
        if ($queue instanceof DelayableQueueInterface) {
          $queue->delayItem($item, $exception->getDelay());
        }
        $summary['deferred']++;
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $summary['requeued']++;
      }
      catch (SuspendQueueException $exception) {
        $queue->releaseItem($item);
        $summary['suspended'] = TRUE;
        $this->logger->warning('The Changelogify synthesis queue was suspended by @exception.', [
          '@exception' => $exception::class,
        ]);
        break;
      }
      catch (\Throwable $exception) {
        // Match Drupal cron semantics: retain the lease so another runner does
        // not immediately repeat a provider request of uncertain disposition.
        $summary['failed']++;
        $this->logger->error('A Changelogify synthesis queue item failed with @exception.', [
          '@exception' => $exception::class,
        ]);
      }
      $this->queueHealth->recordRunner();
    }

    $summary['remaining'] = max(0, (int) $queue->numberOfItems());
    $summary['elapsed'] = round($this->time->getCurrentMicroTime() - $started, 3);
    $this->queueHealth->recordRunner();
    return $summary;
  }

}
