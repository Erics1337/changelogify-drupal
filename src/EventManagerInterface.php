<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;

/**
 * Interface for the Event Manager service.
 */
interface EventManagerInterface {

  /**
   * Logs an event to the event store.
   *
   * @param array $data
   *   Event data with keys:
   *   - event_type: (string) Type of event.
   *   - source: (string) Source of the event.
   *   - message: (string) Event message.
   *   - entity_type_id: (optional string) Entity type.
   *   - entity_id: (optional int) Entity ID.
   *   - bundle: (optional string) Entity bundle.
   *   - section_hint: (optional string) Suggested section.
   *   - metadata: (optional array) Additional data.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface
   *   The created event entity.
   */
  public function logEvent(array $data): ChangelogifyEventInterface;

  /**
   * Gets events within a date range.
   *
   * @param \DateTimeInterface $start
   *   Start of the date range.
   * @param \DateTimeInterface $end
   *   End of the date range.
   * @param array $filters
   *   Optional filters (event_type, source, section_hint).
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface[]
   *   Array of event entities.
   */
  public function getEventsByRange(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): array;

  /**
   * Gets count of events since a given timestamp.
   *
   * @param int $since
   *   Unix timestamp.
   *
   * @return int
   *   Count of events.
   */
  public function getEventCountSince(int $since): int;

  /**
   * Gets events since the last published release.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface[]
   *   Array of event entities.
   */
  public function getEventsSinceLastRelease(): array;

}
