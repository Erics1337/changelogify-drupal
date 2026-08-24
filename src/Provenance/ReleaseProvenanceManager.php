<?php

declare(strict_types=1);

namespace Drupal\changelogify\Provenance;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Updates minimal release evidence without retaining event payloads.
 */
final class ReleaseProvenanceManager implements ReleaseProvenanceManagerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getResolvedProvenance(ChangelogifyReleaseInterface $release): array {
    $provenance = $release->getProvenance();
    $availableIds = [];
    foreach ($provenance['items'] as $item) {
      foreach ($item['events'] ?? [] as $event) {
        if (($event['evidence_status'] ?? NULL) === 'available') {
          $availableIds[] = (int) ($event['event_id'] ?? 0);
        }
      }
    }
    $loaded = $availableIds === [] ? [] : $this->entityTypeManager
      ->getStorage('changelogify_event')
      ->loadMultiple(array_unique($availableIds));
    foreach ($provenance['items'] as &$item) {
      $item['events'] ??= [];
      foreach ($item['events'] as &$event) {
        $eventId = (int) ($event['event_id'] ?? 0);
        if (($event['evidence_status'] ?? NULL) === 'available' && !isset($loaded[$eventId])) {
          $event['evidence_status'] = 'missing';
        }
      }
      unset($event);
      $item['evidence_status'] = $this->itemStatus($item['events'] ?? []);
    }
    unset($item);
    return $provenance;
  }

  /**
   * {@inheritdoc}
   */
  public function markEventsExpired(array $eventIds): void {
    $eventIds = array_fill_keys(array_map('intval', $eventIds), TRUE);
    foreach ($this->loadReleases() as $release) {
      $provenance = $release->getProvenance();
      $changed = FALSE;
      foreach ($provenance['items'] as &$item) {
        $item['events'] ??= [];
        foreach ($item['events'] as &$event) {
          if (isset($eventIds[(int) ($event['event_id'] ?? 0)])) {
            $event['evidence_status'] = 'expired';
            $changed = TRUE;
          }
        }
        unset($event);
        $item['evidence_status'] = $this->itemStatus($item['events'] ?? []);
      }
      unset($item);
      if ($changed) {
        $release->setProvenance($provenance)->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpiredProvenance(int $retentionDays): int {
    if ($retentionDays < 1) {
      return 0;
    }
    $cutoff = $this->time->getCurrentTime() - ($retentionDays * 86400);
    $count = 0;
    foreach ($this->loadReleases() as $release) {
      if ($release->getReleaseDate() >= $cutoff) {
        continue;
      }
      $provenance = $release->getProvenance();
      foreach ($provenance['items'] as &$item) {
        $item['event_ids'] = [];
        $item['events'] = [];
        $item['evidence_status'] = 'removed';
      }
      unset($item);
      $release->setProvenance($provenance)->save();
      $count++;
    }
    return $count;
  }

  /**
   * {@inheritdoc}
   */
  public function backfillExistingReleases(): int {
    $count = 0;
    foreach ($this->loadReleases() as $release) {
      if ($release->getProvenance()['items'] !== []) {
        continue;
      }
      $items = [];
      foreach ($release->getSections() as $section => $sectionItems) {
        foreach ($sectionItems as $item) {
          $events = $this->loadEventSnapshots($item['event_ids'] ?? []);
          $itemId = (string) $item['id'];
          $items[$itemId] = [
            'change_set_id' => $itemId,
            'kind' => 'legacy_release_item',
            'section' => $section,
            'event_ids' => array_values(array_map('intval', $item['event_ids'] ?? [])),
            'evidence_status' => $this->itemStatus($events),
            'events' => $events,
          ];
        }
      }
      $release->setProvenance(['version' => 1, 'items' => $items])->save();
      $count++;
    }
    return $count;
  }

  /**
   * Loads all releases for bounded maintenance operations.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface[]
   *   Release entities.
   */
  private function loadReleases(): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    return $ids === [] ? [] : $storage->loadMultiple($ids);
  }

  /**
   * Loads safe snapshots for currently available event IDs.
   */
  private function loadEventSnapshots(array $eventIds): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_event');
    $validIds = [];
    foreach ($eventIds as $eventId) {
      $validated = filter_var($eventId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
      ]);
      if ($validated !== FALSE) {
        $validIds[] = $validated;
      }
    }
    $events = $storage->loadMultiple($validIds);
    $snapshots = [];
    foreach ($eventIds as $eventId) {
      $validated = filter_var($eventId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
      ]);
      if ($validated === FALSE) {
        $snapshots[] = [
          'event_id' => NULL,
          'evidence_status' => 'invalid',
        ];
        continue;
      }
      $event = $events[$validated] ?? NULL;
      if ($event instanceof ChangelogifyEventInterface) {
        $snapshots[] = $this->snapshot($event);
      }
      else {
        $snapshots[] = [
          'event_id' => $validated,
          'evidence_status' => 'missing',
        ];
      }
    }
    return $snapshots;
  }

  /**
   * Creates a strictly redacted event snapshot.
   */
  private function snapshot(ChangelogifyEventInterface $event): array {
    return [
      'event_id' => (int) $event->id(),
      'event_uuid' => $event->uuid(),
      'event_type' => $event->getEventType(),
      'source' => $event->getSource(),
      'timestamp' => $event->getTimestamp(),
      'schema_version' => $event->getSchemaVersion(),
      'correlation_id' => $event->getCorrelationId(),
      'entity_type_id' => $event->getRelatedEntityTypeId(),
      'entity_id' => $event->getRelatedEntityId(),
      'bundle' => $event->getRelatedBundle(),
      'evidence_status' => 'available',
    ];
  }

  /**
   * Derives an item status from its individual evidence statuses.
   */
  private function itemStatus(array $events): string {
    if ($events === []) {
      return 'removed';
    }
    $statuses = array_unique(array_column($events, 'evidence_status'));
    return count($statuses) === 1 ? (string) reset($statuses) : 'partial';
  }

}
