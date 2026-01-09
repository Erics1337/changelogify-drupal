<?php

declare(strict_types=1);

namespace Drupal\changelogify;

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
    $sections = $this->groupEventsBySection($events);

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
   * Generates a default title based on date range.
   */
  protected function generateDefaultTitle(\DateTimeInterface $start, \DateTimeInterface $end): string {
    $end_date = $end->format('F Y');
    return $this->t('Release - @date', ['@date' => $end_date])->__toString();
  }

}
