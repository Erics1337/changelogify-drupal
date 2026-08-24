<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;

/**
 * Manages event logging and retrieval.
 */
class EventManager implements EventManagerInterface {

  /**
   * Constructs an EventManager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ReleaseProvenanceManagerInterface $provenanceManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function logEvent(array $data): ChangelogifyEventInterface {
    return $this->logEventInput(EventInput::fromArray(
      $data,
      (int) $this->time->getRequestTime(),
      (int) $this->currentUser->id(),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function logEventInput(EventInput $input): ChangelogifyEventInterface {
    $storage = $this->entityTypeManager->getStorage('changelogify_event');

    $event_data = [
      'schema_version' => $input->schemaVersion,
      'timestamp' => $input->timestamp,
      'event_type' => $input->eventType,
      'source' => $input->source,
      'message' => $input->message,
      'user_id' => $input->actorId,
      'metadata' => json_encode($input->metadata, JSON_THROW_ON_ERROR),
    ];

    $optionalFields = [
      'entityTypeId' => 'entity_type_id',
      'entityId' => 'entity_id',
      'bundle' => 'bundle',
      'sectionHint' => 'section_hint',
      'correlationId' => 'correlation_id',
    ];
    foreach ($optionalFields as $property => $field) {
      if ($input->{$property} !== NULL) {
        $event_data[$field] = $input->{$property};
      }
    }
    /** @var \Drupal\changelogify\Entity\ChangelogifyEventInterface $event */
    $event = $storage->create($event_data);
    $event->save();

    return $event;
  }

  /**
   * {@inheritdoc}
   */
  public function getEventsByRange(\DateTimeInterface $start, \DateTimeInterface $end, array $filters = []): array {
    $storage = $this->entityTypeManager->getStorage('changelogify_event');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('timestamp', $start->getTimestamp(), '>=')
      ->condition('timestamp', $end->getTimestamp(), '<=')
      ->sort('timestamp', 'ASC')
      ->sort('id', 'ASC');

    if (!empty($filters['event_type'])) {
      $query->condition('event_type', $filters['event_type']);
    }
    if (!empty($filters['source'])) {
      $query->condition('source', $filters['source']);
    }
    if (!empty($filters['section_hint'])) {
      $query->condition('section_hint', $filters['section_hint']);
    }
    if (!empty($filters['correlation_id'])) {
      $query->condition('correlation_id', $filters['correlation_id']);
    }
    if (isset($filters['schema_version'])) {
      $query->condition('schema_version', $filters['schema_version']);
    }
    if (isset($filters['limit'])) {
      $limit = filter_var($filters['limit'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
      ]);
      if ($limit === FALSE) {
        throw new \InvalidArgumentException('The event query limit must be a positive integer.');
      }
      $query->range(0, $limit);
    }

    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getEventCountSince(int $since): int {
    $storage = $this->entityTypeManager->getStorage('changelogify_event');

    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('timestamp', $since, '>=')
      ->count()
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getEventsSinceLastRelease(): array {
    $start = new \DateTimeImmutable('@' . $this->getNextReleaseStartTimestamp());
    $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());

    return $this->getEventsByRange($start, $end);
  }

  /**
   * {@inheritdoc}
   */
  public function getNextReleaseStartTimestamp(): int {
    $release_storage = $this->entityTypeManager->getStorage('changelogify_release');
    $release_ids = $release_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($release_ids)) {
      return 0;
    }

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface|null $release */
    $release = $release_storage->load(reset($release_ids));
    if ($release === NULL) {
      return 0;
    }

    $boundary = (int) ($release->get('date_end')->value ?? $release->getReleaseDate());
    // Include the exact boundary so events sharing its timestamp are visible
    // in preview. Release coverage analysis identifies already-used evidence
    // and requires editors to confirm intentional reuse.
    return $boundary;
  }

  /**
   * {@inheritdoc}
   */
  public function getEventCountSinceLastRelease(): int {
    return $this->getEventCountSince($this->getNextReleaseStartTimestamp());
  }

  /**
   * {@inheritdoc}
   */
  public function purgeExpiredEvents(int $retention_days, int $limit = 1000): int {
    if ($retention_days < 1 || $limit < 1) {
      return 0;
    }

    $storage = $this->entityTypeManager->getStorage('changelogify_event');
    $cutoff = $this->time->getCurrentTime() - ($retention_days * 86400);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('timestamp', $cutoff, '<')
      ->sort('timestamp', 'ASC')
      ->range(0, $limit)
      ->execute();

    if (empty($ids)) {
      return 0;
    }

    $this->provenanceManager->markEventsExpired(array_map('intval', $ids));
    $events = $storage->loadMultiple($ids);
    $storage->delete($events);
    return count($events);
  }

}
