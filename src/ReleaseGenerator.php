<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Generates releases from events.
 */
class ReleaseGenerator implements ReleaseGeneratorInterface {

  use StringTranslationTrait;

  /**
   * Maximum events loaded into one release generation request.
   */
  private const MAX_EVENTS_PER_RELEASE = 5000;

  /**
   * Constructs a ReleaseGenerator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventManagerInterface $eventManager,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function generateReleaseFromRange(\DateTimeInterface $start, \DateTimeInterface $end, array $options = []): ChangelogifyReleaseInterface {
    if ($start > $end) {
      throw new \InvalidArgumentException('The release start date must not be after its end date.');
    }

    $events = $this->loadEventsForRelease($start, $end);
    return $this->createReleaseFromEvents($events, $start, $end, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function generateReleaseSinceLast(array $options = []): ChangelogifyReleaseInterface {
    $start = new \DateTimeImmutable('@' . $this->eventManager->getNextReleaseStartTimestamp());
    $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());
    $events = $this->loadEventsForRelease($start, $end);

    return $this->createReleaseFromEvents($events, $start, $end, $options);
  }

  /**
   * Creates a release entity from events.
   */
  protected function createReleaseFromEvents(array $events, \DateTimeInterface $start, \DateTimeInterface $end, array $options): ChangelogifyReleaseInterface {
    if ($start > $end) {
      throw new \InvalidArgumentException('The release start date must not be after its end date.');
    }

    $sections = $this->groupEventsBySection($this->normalizeEvents($events));

    $storage = $this->entityTypeManager->getStorage('changelogify_release');

    $title = $options['title'] ?? $this->generateDefaultTitle($start, $end);

    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => $title,
      'label_type' => $options['label_type'] ?? 'date_range',
      'version' => $options['version'] ?? NULL,
      'release_date' => $this->time->getRequestTime(),
      'date_start' => $start->getTimestamp(),
      'date_end' => $end->getTimestamp(),
      'status' => FALSE,
      'uid' => $this->currentUser->id(),
    ]);

    $release->setSections($sections);
    $release->save();

    return $release;
  }

  /**
   * Loads a bounded event set and rejects ranges that are too broad.
   */
  private function loadEventsForRelease(\DateTimeInterface $start, \DateTimeInterface $end): array {
    $events = $this->eventManager->getEventsByRange($start, $end, [
      'limit' => self::MAX_EVENTS_PER_RELEASE + 1,
    ]);
    if (count($events) > self::MAX_EVENTS_PER_RELEASE) {
      throw new \LengthException(sprintf(
        'A release can contain at most %d events. Use a narrower date range.',
        self::MAX_EVENTS_PER_RELEASE,
      ));
    }

    return $events;
  }

  /**
   * Groups events by their section hint.
   */
  protected function groupEventsBySection(array $events): array {
    $sections = [
      'added' => [],
      'changed' => [],
      'fixed' => [],
      'removed' => [],
      'security' => [],
      'other' => [],
    ];

    $itemIndexes = [];
    foreach ($events as $event) {
      $hint = $event->getSectionHint() ?? 'other';
      if (!isset($sections[$hint])) {
        $hint = 'other';
      }

      $message = trim($event->getMessage());
      $itemKey = $hint . "\0" . $message;
      if (isset($itemIndexes[$itemKey])) {
        $sections[$hint][$itemIndexes[$itemKey]]['event_ids'][] = (int) $event->id();
      }
      else {
        $itemIndexes[$itemKey] = count($sections[$hint]);
        $sections[$hint][] = [
          'id' => $event->uuid(),
          'text' => $message,
          'event_ids' => [(int) $event->id()],
        ];
      }
    }

    return $sections;
  }

  /**
   * Removes empty and redundant update events before grouping.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyEventInterface[] $events
   *   Events in chronological order.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface[]
   *   Normalized events.
   */
  private function normalizeEvents(array $events): array {
    $publicationChanges = [];
    foreach ($events as $event) {
      if ($this->isPublicationEvent($event)) {
        $publicationChanges[$this->buildEntityTimestampKey($event)] = TRUE;
      }
    }

    return array_values(array_filter(
      $events,
      fn (ChangelogifyEventInterface $event): bool => trim($event->getMessage()) !== ''
        && !$this->shouldSuppressUpdate($event, $publicationChanges),
    ));
  }

  /**
   * Determines whether an event is a publication state change.
   */
  private function isPublicationEvent(ChangelogifyEventInterface $event): bool {
    return str_ends_with($event->getEventType(), '_published')
      || str_ends_with($event->getEventType(), '_unpublished');
  }

  /**
   * Suppresses a generic update emitted with a publication change.
   */
  private function shouldSuppressUpdate(ChangelogifyEventInterface $event, array $publicationChanges): bool {
    if (!str_ends_with($event->getEventType(), '_updated')
      && $event->getEventType() !== 'content_updated') {
      return FALSE;
    }

    return isset($publicationChanges[$this->buildEntityTimestampKey($event)]);
  }

  /**
   * Builds a key for matching related events at the same timestamp.
   */
  private function buildEntityTimestampKey(ChangelogifyEventInterface $event): string {
    return implode(':', [
      (string) $event->getRelatedEntityTypeId(),
      (string) $event->getRelatedEntityId(),
      (string) $event->getTimestamp(),
    ]);
  }

  /**
   * Generates a default title based on date range.
   */
  protected function generateDefaultTitle(\DateTimeInterface $start, \DateTimeInterface $end): string {
    $end_date = $end->format('F Y');
    return $this->t('Release - @date', ['@date' => $end_date])->__toString();
  }

}
