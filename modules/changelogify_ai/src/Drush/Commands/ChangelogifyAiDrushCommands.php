<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Drush\Commands;

use Drupal\changelogify_ai\SynthesisQueueRunner;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Provides production-safe Changelogify AI worker commands.
 */
final class ChangelogifyAiDrushCommands extends DrushCommands {

  use AutowireTrait;

  public const RUN = 'changelogify:ai-worker';

  public function __construct(private readonly SynthesisQueueRunner $runner) {
    parent::__construct();
  }

  /**
   * Processes only queued Changelogify synthesis work.
   */
  #[CLI\Command(name: self::RUN)]
  #[CLI\Option(name: 'time-limit', description: 'Maximum worker runtime in seconds.')]
  #[CLI\Option(name: 'items-limit', description: 'Maximum queue references to claim; zero is unlimited within the time limit.')]
  #[CLI\Option(name: 'lease-time', description: 'Seconds a claimed reference remains unavailable to another worker.')]
  #[CLI\Usage(name: 'drush changelogify:ai-worker --time-limit=55', description: 'Run from a once-per-minute production scheduler.')]
  public function run(
    array $options = [
      'time-limit' => 55,
      'items-limit' => 0,
      'lease-time' => 120,
    ],
  ): int {
    $summary = $this->runner->run(
      (int) $options['time-limit'],
      (int) $options['items-limit'],
      (int) $options['lease-time'],
    );
    $this->logger()->notice('Changelogify synthesis worker attempted @attempted item(s), completed @completed, and left @remaining queued in @elapsed seconds.', [
      '@attempted' => $summary['attempted'],
      '@completed' => $summary['completed'],
      '@remaining' => $summary['remaining'],
      '@elapsed' => $summary['elapsed'],
    ]);
    if ($summary['failed'] > 0 || $summary['suspended']) {
      $this->logger()->error('The Changelogify synthesis worker encountered a failure. Review Drupal logs before retrying.');
      return 1;
    }
    return 0;
  }

}
