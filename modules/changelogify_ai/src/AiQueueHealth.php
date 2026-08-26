<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Reports privacy-safe cron and synthesis queue health.
 */
final class AiQueueHealth {

  private const LAST_CRON = 'changelogify_ai.last_cron';
  private const LAST_RUNNER = 'changelogify_ai.last_synthesis_runner';
  private const LAST_WORKER = 'changelogify_ai.last_synthesis_worker';
  public const STALL_SECONDS = 900;

  public function __construct(private readonly StateInterface $state, private readonly Connection $database, private readonly TimeInterface $time, private readonly ConfigFactoryInterface $configFactory, private readonly ModuleHandlerInterface $moduleHandler) {}

  /**
   * Records that site cron invoked the module.
   */
  public function recordCron(): void {
    $this->state->set(self::LAST_CRON, $this->time->getRequestTime());
  }

  /**
   * Records that a dedicated synthesis runner is available.
   */
  public function recordRunner(): void {
    $this->state->set(self::LAST_RUNNER, $this->time->getRequestTime());
  }

  /**
   * Records that the synthesis worker claimed an item.
   */
  public function recordWorker(): void {
    $this->state->set(self::LAST_WORKER, $this->time->getRequestTime());
  }

  /**
   * Returns safe queue counts and heartbeat timestamps.
   */
  public function status(?int $jobCreated = NULL): array {
    $result = [];
    if ($this->database->schema()->tableExists('queue')) {
      $query = $this->database->select('queue', 'q');
      $query->condition('name', SynthesisJobManager::QUEUE_NAME);
      $query->addExpression('COUNT(*)', 'queued_count');
      $query->addExpression('MIN(created)', 'oldest_created');
      $result = $query->execute()->fetchAssoc() ?: [];
    }
    $lastCron = (int) ($this->state->get(self::LAST_CRON) ?: $this->state->get('system.cron_last') ?: 0);
    $lastRunner = (int) ($this->state->get(self::LAST_RUNNER) ?: 0);
    $lastWorker = (int) ($this->state->get(self::LAST_WORKER) ?: 0);
    $now = $this->time->getRequestTime();
    $created = $jobCreated ?? (int) ($result['oldest_created'] ?? 0);
    $lastProcessorActivity = max($lastRunner, $lastWorker);
    $workerInactive = $lastProcessorActivity < $created
      || ($now - $lastProcessorActivity) >= self::STALL_SECONDS;
    $cronInactive = $lastCron < $created || ($now - $lastCron) >= self::STALL_SECONDS;
    $delayed = $created > 0
      && ($now - $created) >= self::STALL_SECONDS
      && $workerInactive
      && $cronInactive;
    $automatedCronInterval = $this->moduleHandler->moduleExists('automated_cron')
      ? max(0, (int) $this->configFactory->get('automated_cron.settings')->get('interval'))
      : 0;
    $nextAutomatedCron = $automatedCronInterval > 0
      ? ($lastCron > 0 ? $lastCron + $automatedCronInterval : $now)
      : 0;
    $lastActivity = max($lastCron, $lastProcessorActivity);
    $recentActivity = $lastActivity > 0 && ($now - $lastActivity) < self::STALL_SECONDS;
    $processorState = match (TRUE) {
      (int) ($result['queued_count'] ?? 0) === 0 => 'idle',
      $delayed => 'delayed',
      $lastProcessorActivity >= $created
        && ($now - $lastProcessorActivity) < self::STALL_SECONDS => 'active',
      $nextAutomatedCron >= $now => 'scheduled',
      $recentActivity => 'active',
      default => 'unavailable',
    };
    return [
      'last_cron' => $lastCron,
      'last_runner' => $lastRunner,
      'last_worker' => $lastWorker,
      'last_activity' => $lastActivity,
      'queued_count' => (int) ($result['queued_count'] ?? 0),
      'oldest_created' => (int) ($result['oldest_created'] ?? 0),
      'automated_cron_interval' => $automatedCronInterval,
      'next_automated_cron' => $nextAutomatedCron,
      'processor_state' => $processorState,
      'delayed' => $delayed,
    ];
  }

}
