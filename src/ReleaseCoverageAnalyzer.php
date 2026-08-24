<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Detects release-window coverage risks and reused event evidence.
 */
final class ReleaseCoverageAnalyzer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventReleaseUsage $releaseUsage,
  ) {
  }

  /**
   * Analyzes a candidate release window and its change sets.
   *
   * @param int $start
   *   Inclusive candidate-window start timestamp.
   * @param int $end
   *   Inclusive candidate-window end timestamp.
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Candidate change sets.
   */
  public function analyze(int $start, int $end, array $changeSets): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('date_start')
      ->exists('date_end')
      ->sort('date_start', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $overlaps = [];
    $latestPriorEnd = NULL;
    foreach ($storage->loadMultiple($ids) as $release) {
      $releaseStart = (int) $release->get('date_start')->value;
      $releaseEnd = (int) $release->get('date_end')->value;
      if ($releaseEnd < $start) {
        $latestPriorEnd = max($latestPriorEnd ?? $releaseEnd, $releaseEnd);
      }
      if ($start <= $releaseEnd && $end >= $releaseStart) {
        $overlaps[] = [
          'release_id' => (int) $release->id(),
          'title' => (string) $release->label(),
          'status' => $release->isPublished() ? 'published' : 'draft',
          'start' => $releaseStart,
          'end' => $releaseEnd,
        ];
      }
    }

    $reused = [];
    $usage = $this->releaseUsage->getUsage();
    foreach ($changeSets as $changeSet) {
      assert($changeSet instanceof ChangeSet);
      $releases = [];
      foreach ($changeSet->sourceEventIds as $eventId) {
        foreach ($usage[$eventId] ?? [] as $releaseId => $title) {
          $releases[$releaseId] = $title;
        }
      }
      if ($releases !== []) {
        $reused[$changeSet->id] = $releases;
      }
    }
    return [
      'overlaps' => $overlaps,
      'gap_before' => $latestPriorEnd !== NULL && $start > $latestPriorEnd + 1
        ? ['start' => $latestPriorEnd + 1, 'end' => $start - 1]
        : NULL,
      'reused_change_sets' => $reused,
    ];
  }

}
