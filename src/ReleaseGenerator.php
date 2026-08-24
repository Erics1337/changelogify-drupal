<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\ChangeSet\ChangeSetAggregatorInterface;
use Drupal\Component\Datetime\TimeInterface;
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
    protected ChangeSetAggregatorInterface $changeSetAggregator,
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

    $aggregation = $this->changeSetAggregator->aggregate($events);
    $sections = $this->groupChangeSetsBySection($aggregation->changeSets);

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
   * Groups coherent change sets into backwards-compatible release items.
   */
  protected function groupChangeSetsBySection(array $changeSets): array {
    $sections = array_fill_keys([
      'added',
      'changed',
      'fixed',
      'removed',
      'security',
      'other',
    ], []);
    foreach ($changeSets as $changeSet) {
      $hint = isset($sections[$changeSet->suggestedSection])
        ? $changeSet->suggestedSection
        : 'other';
      $sections[$hint][] = [
        'id' => $changeSet->id,
        'text' => trim((string) ($changeSet->summaryContext['message'] ?? '')),
        'event_ids' => $changeSet->sourceEventIds,
      ];
    }
    return $sections;
  }

  /**
   * Generates a default title based on date range.
   */
  protected function generateDefaultTitle(\DateTimeInterface $start, \DateTimeInterface $end): string {
    $end_date = $end->format('F Y');
    return $this->t('Release - @date', ['@date' => $end_date])->__toString();
  }

}
