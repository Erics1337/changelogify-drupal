<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\ChangeSet\ChangeSetAggregatorInterface;
use Drupal\changelogify\ChangeSet\ChangeSet;
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
  public function previewRange(\DateTimeInterface $start, \DateTimeInterface $end): ReleasePreview {
    if ($start > $end) {
      throw new \InvalidArgumentException('The release start date must not be after its end date.');
    }
    $events = $this->loadEventsForRelease($start, $end);
    return new ReleasePreview(
      $start->getTimestamp(),
      $end->getTimestamp(),
      $this->changeSetAggregator->aggregate($events)->changeSets,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function previewSinceLast(): ReleasePreview {
    $start = new \DateTimeImmutable('@' . $this->eventManager->getNextReleaseStartTimestamp());
    $end = new \DateTimeImmutable('@' . $this->time->getRequestTime());
    return $this->previewRange($start, $end);
  }

  /**
   * {@inheritdoc}
   */
  public function generateReleaseFromSelection(
    \DateTimeInterface $start,
    \DateTimeInterface $end,
    array $selection,
    array $options = [],
    bool $allowEmpty = FALSE,
  ): ChangelogifyReleaseInterface {
    $preview = $this->previewRange($start, $end);
    $available = [];
    foreach ($preview->changeSets as $changeSet) {
      $available[$changeSet->id] = $changeSet;
    }
    $selected = [];
    $validSections = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];
    foreach ($selection as $changeSetId => $section) {
      if (!isset($available[$changeSetId])) {
        throw new \UnexpectedValueException(sprintf(
          'Selected change set %s is stale or its evidence is no longer available.',
          $changeSetId,
        ));
      }
      if (!in_array($section, $validSections, TRUE)) {
        throw new \InvalidArgumentException('A selected release section is invalid.');
      }
      $candidate = $available[$changeSetId];
      $selected[] = new ChangeSet(
        id: $candidate->id,
        kind: $candidate->kind,
        startTimestamp: $candidate->startTimestamp,
        endTimestamp: $candidate->endTimestamp,
        sourceEventIds: $candidate->sourceEventIds,
        suggestedSection: $section,
        summaryContext: $candidate->summaryContext,
        provenance: $candidate->provenance,
      );
    }
    if ($selected === [] && !$allowEmpty) {
      throw new \UnexpectedValueException('Creating an empty release requires explicit confirmation.');
    }
    return $this->createReleaseFromChangeSets($selected, $start, $end, $options);
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
    return $this->createReleaseFromChangeSets($aggregation->changeSets, $start, $end, $options);
  }

  /**
   * Creates and saves a release from already validated change sets.
   */
  private function createReleaseFromChangeSets(array $changeSets, \DateTimeInterface $start, \DateTimeInterface $end, array $options): ChangelogifyReleaseInterface {
    [$sections, $provenance] = $this->buildReleaseData($changeSets);

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
    $release->setProvenance($provenance);
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
  protected function buildReleaseData(array $changeSets): array {
    $sections = array_fill_keys([
      'added',
      'changed',
      'fixed',
      'removed',
      'security',
      'other',
    ], []);
    $provenance = ['version' => 1, 'items' => []];
    foreach ($changeSets as $changeSet) {
      $hint = isset($sections[$changeSet->suggestedSection])
        ? $changeSet->suggestedSection
        : 'other';
      $sections[$hint][] = [
        'id' => $changeSet->id,
        'text' => trim((string) ($changeSet->summaryContext['message'] ?? '')),
        'event_ids' => $changeSet->sourceEventIds,
      ];
      $provenance['items'][$changeSet->id] = [
        'change_set_id' => $changeSet->id,
        'kind' => $changeSet->kind,
        'section' => $hint,
        'event_ids' => $changeSet->sourceEventIds,
        'event_count' => $changeSet->provenance['event_count'] ?? count($changeSet->sourceEventIds),
        'evidence_status' => $changeSet->provenance['evidence_status'] ?? 'available',
        'events' => $changeSet->provenance['events'] ?? [],
      ];
    }
    return [$sections, $provenance];
  }

  /**
   * Generates a default title based on date range.
   */
  protected function generateDefaultTitle(\DateTimeInterface $start, \DateTimeInterface $end): string {
    $end_date = $end->format('F Y');
    return $this->t('Release - @date', ['@date' => $end_date])->__toString();
  }

}
