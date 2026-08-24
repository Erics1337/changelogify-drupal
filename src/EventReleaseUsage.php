<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves which retained events are referenced by releases.
 */
final class EventReleaseUsage {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Returns event IDs keyed to IDs and labels of referencing releases.
   */
  public function getUsage(): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $usage = [];
    $lastId = 0;
    do {
      $ids = array_values($storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('id', $lastId, '>')
        ->sort('id', 'ASC')
        ->range(0, 100)
        ->execute());
      foreach ($storage->loadMultiple($ids) as $release) {
        foreach ($release->getSections() as $items) {
          foreach ($items as $item) {
            foreach ($item['event_ids'] ?? [] as $eventId) {
              $usage[(int) $eventId][(int) $release->id()] = (string) $release->label();
            }
          }
        }
      }
      if ($ids !== []) {
        $lastId = (int) end($ids);
        $storage->resetCache($ids);
      }
    } while (count($ids) === 100);
    return $usage;
  }

}
