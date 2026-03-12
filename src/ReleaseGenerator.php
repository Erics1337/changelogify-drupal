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
    $events = $this->eventManager->getEventsByRange($start, $end);
    return $this->createReleaseFromEvents($events, $start, $end, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function generateReleaseSinceLast(array $options = []): ChangelogifyReleaseInterface {
    $events = $this->eventManager->getEventsSinceLastRelease();

    // Determine start date from last release.
    $release_storage = $this->entityTypeManager->getStorage('changelogify_release');
    $release_ids = $release_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->range(0, 1)
      ->execute();

    $start_timestamp = 0;
    if (!empty($release_ids)) {
      /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $last_release */
      $last_release = $release_storage->load(reset($release_ids));
      $start_timestamp = $last_release->get('date_end')->value ?? $last_release->getReleaseDate();
    }

    $start = new \DateTimeImmutable('@' . $start_timestamp);
    $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());

    return $this->createReleaseFromEvents($events, $start, $end, $options);
  }

  /**
   * Creates a release entity from events.
   */
  protected function createReleaseFromEvents(array $events, \DateTimeInterface $start, \DateTimeInterface $end, array $options): ChangelogifyReleaseInterface {
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

    foreach ($events as $event) {
      $hint = $event->getSectionHint() ?? 'other';
      if (!isset($sections[$hint])) {
        $hint = 'other';
      }

      $sections[$hint][] = [
        'id' => $event->uuid(),
        'text' => $event->getMessage(),
        'event_ids' => [$event->id()],
      ];
    }

    return $sections;
  }

  /**
   * Removes noisy duplicate events before section grouping.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyEventInterface[] $events
   *   Raw events in ascending timestamp order.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface[]
   *   Normalized events.
   */
  protected function normalizeEvents(array $events): array {
    $seen_messages = [];
    $node_state_changes = [];

    foreach ($events as $event) {
      if ($this->isNodePublicationEvent($event)) {
        $node_state_changes[$this->buildNodeTimestampKey($event)] = TRUE;
      }
    }

    $normalized = [];
    foreach ($events as $event) {
      $message = trim($event->getMessage());
      if ($message === '') {
        continue;
      }

      if (isset($seen_messages[$message])) {
        continue;
      }

      $seen_messages[$message] = TRUE;

      if ($this->shouldSuppressNodeUpdate($event, $node_state_changes)) {
        continue;
      }
      $normalized[] = $event;
    }

    return $normalized;
  }

  /**
   * Determines whether an event represents a node publication state change.
   */
  protected function isNodePublicationEvent(ChangelogifyEventInterface $event): bool {
    return in_array($event->getEventType(), ['node_published', 'node_unpublished'], TRUE)
      && $event->getRelatedEntityTypeId() === 'node'
      && $event->getRelatedEntityId() !== NULL;
  }

  /**
   * Determines whether a node update should be hidden in favor of publish state.
   */
  protected function shouldSuppressNodeUpdate(ChangelogifyEventInterface $event, array $node_state_changes): bool {
    if (!in_array($event->getEventType(), ['node_updated', 'content_updated'], TRUE)) {
      return FALSE;
    }

    if ($event->getRelatedEntityTypeId() !== 'node' || $event->getRelatedEntityId() === NULL) {
      return FALSE;
    }

    return isset($node_state_changes[$this->buildNodeTimestampKey($event)]);
  }

  /**
   * Builds a key for matching node updates with publish state changes.
   */
  protected function buildNodeTimestampKey(ChangelogifyEventInterface $event): string {
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
