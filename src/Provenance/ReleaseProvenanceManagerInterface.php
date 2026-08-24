<?php

declare(strict_types=1);

namespace Drupal\changelogify\Provenance;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Maintains privacy-bounded release evidence across retention operations.
 */
interface ReleaseProvenanceManagerInterface {

  /**
   * Gets provenance with unresolved available references marked missing.
   */
  public function getResolvedProvenance(ChangelogifyReleaseInterface $release): array;

  /**
   * Marks raw event evidence expired before event deletion.
   *
   * @param int[] $eventIds
   *   Event IDs being expired.
   */
  public function markEventsExpired(array $eventIds): void;

  /**
   * Redacts provenance older than its separate retention period.
   */
  public function purgeExpiredProvenance(int $retentionDays): int;

  /**
   * Backfills all existing releases from currently available raw events.
   */
  public function backfillExistingReleases(): int;

}
