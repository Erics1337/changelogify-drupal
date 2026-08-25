<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;

/**
 * Loads and formats accessible public releases for reusable presentation.
 */
final class PublicReleaseBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Loads a bounded, newest-first list of accessible published releases.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface[]
   *   Accessible published releases.
   */
  public function load(int $limit): array {
    $limit = min(20, max(1, $limit));
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    return array_values(array_filter(
      $storage->loadMultiple($ids),
      fn (object $release): bool => $release instanceof ChangelogifyReleaseInterface
        && $release->access('view', $this->currentUser),
    ));
  }

  /**
   * Builds one safe public presentation record.
   */
  public function build(ChangelogifyReleaseInterface $release, array $includedSections): array {
    $allowed = array_fill_keys($includedSections, TRUE);
    $sections = [];
    foreach ($release->getSections() as $key => $items) {
      if (!isset($allowed[$key]) || $items === []) {
        continue;
      }
      $sections[$key] = [
        'label' => $this->sectionLabel($key),
        'items' => array_map(
          static fn (array $item): array => ['text' => (string) ($item['text'] ?? '')],
          $items,
        ),
      ];
    }
    return [
      'title' => $release->getTitle(),
      'date' => $this->dateFormatter->format($release->getReleaseDate(), 'medium'),
      'date_iso' => $this->dateFormatter->format($release->getReleaseDate(), 'custom', 'c'),
      'version' => $release->getVersion(),
      'sections' => $sections,
      'url' => $this->releaseUrl($release->getSlug())->toString(),
    ];
  }

  /**
   * Returns the configured changelog URL without assuming the default route.
   */
  public function changelogUrl(): Url {
    return Url::fromUserInput($this->basePath());
  }

  /**
   * Returns the configured URL for one release slug.
   */
  public function releaseUrl(string $slug): Url {
    return Url::fromUserInput($this->basePath() . '/' . rawurlencode($slug));
  }

  /**
   * Returns a translated public section label.
   */
  private function sectionLabel(string $section): string {
    return (string) match ($section) {
      'added' => t('Added'),
      'changed' => t('Changed'),
      'fixed' => t('Fixed'),
      'removed' => t('Removed'),
      'security' => t('Security'),
      'other' => t('Other'),
      default => ucfirst($section),
    };
  }

  /**
   * Resolves the configured public prefix.
   */
  private function basePath(): string {
    $configured = (string) $this->configFactory
      ->get('changelogify.settings')
      ->get('changelog_path');
    return '/' . trim($configured ?: '/changelog', '/');
  }

}
