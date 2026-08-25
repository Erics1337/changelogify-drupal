<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes due releases only when their reviewed content remains unchanged.
 */
final class ScheduledPublicationManager {

  private const BATCH_SIZE = 25;

  /**
   * Module logging channel.
   */
  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('changelogify');
  }

  /**
   * Processes a bounded batch of due publication schedules.
   *
   * @return array{published: int, stale: int}
   *   Counts of published and invalidated schedules.
   */
  public function processDue(int $limit = self::BATCH_SIZE): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('scheduled_at', 0, '>')
      ->condition('scheduled_at', $this->time->getCurrentTime(), '<=')
      ->sort('scheduled_at', 'ASC')
      ->range(0, min(self::BATCH_SIZE, max(1, $limit)))
      ->execute();
    $result = ['published' => 0, 'stale' => 0];
    foreach ($ids as $id) {
      $lockName = 'changelogify.release_schedule.' . $id;
      if (!$this->lock->acquire($lockName, 30.0)) {
        continue;
      }
      try {
        $storage->resetCache([$id]);
        $release = $storage->load($id);
        if (!$release instanceof ChangelogifyReleaseInterface
          || $release->getScheduledPublicationTime() === 0
          || $release->getScheduledPublicationTime() > $this->time->getCurrentTime()) {
          continue;
        }
        $approvedRevisionId = $release->getScheduledRevisionId();
        $approved = $approvedRevisionId === NULL ? NULL : $storage->loadRevision($approvedRevisionId);
        if (!$approved instanceof ChangelogifyReleaseInterface
          || $approved->getEditorialState() !== 'review'
          || $release->getEditorialState() !== 'review'
          || $this->contentSignature($approved) !== $this->contentSignature($release)) {
          $release->setPublicationSchedule();
          $release->setNewRevision(TRUE);
          $release->setRevisionLogMessage('Scheduled publication canceled because the approved release content changed.');
          $release->save();
          $result['stale']++;
          $this->logger->warning('Canceled stale scheduled publication for release @id.', ['@id' => $id]);
          continue;
        }
        $release->setEditorialState('published');
        $release->setPublicationSchedule();
        $release->setNewRevision(TRUE);
        $release->setRevisionLogMessage('Published automatically at the scheduled time.');
        $release->save();
        $result['published']++;
        $this->logger->notice('Published scheduled release @id.', ['@id' => $id]);
      }
      finally {
        $this->lock->release($lockName);
      }
    }
    return $result;
  }

  /**
   * Returns a signature of all public editorial content.
   */
  private function contentSignature(ChangelogifyReleaseInterface $release): string {
    return hash('sha256', serialize([
      $release->getTitle(),
      $release->getVersion(),
      $release->getSlug(),
      $release->getReleaseDate(),
      (int) $release->get('date_start')->value,
      (int) $release->get('date_end')->value,
      $release->getSections(),
    ]));
  }

}
