<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Controller for public changelog pages.
 */
class ChangelogController extends ControllerBase {

  /**
   * Constructs a ChangelogController.
   */
  public function __construct(
    protected DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('date.formatter')
    );
  }

  /**
   * Displays the public changelog listing.
   */
  public function listing(): array {
    $storage = $this->entityTypeManager()->getStorage('changelogify_release');

    $release_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->pager(10)
      ->execute();

    $releases = $storage->loadMultiple($release_ids);
    $cache_tags = $storage->getEntityType()->getListCacheTags();

    $items = [];
    foreach ($releases as $release) {
      $sections = $release->getSections();
      $excerpt = $this->buildExcerpt($sections);
      $cache_tags = Cache::mergeTags($cache_tags, $release->getCacheTags());

      $items[] = [
        'release' => $release,
        'date' => $this->dateFormatter->format($release->getReleaseDate(), 'medium'),
        'excerpt' => $excerpt,
      ];
    }

    return [
      '#theme' => 'changelogify_release_list',
      '#releases' => $items,
      '#attached' => [
        'library' => ['changelogify/public'],
      ],
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['user.permissions'],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Displays a single release.
   */
  public function view(ChangelogifyReleaseInterface $changelogify_release): array {
    $sections = $changelogify_release->getSections();
    $section_labels = [
      'added' => $this->t('Added'),
      'changed' => $this->t('Changed'),
      'fixed' => $this->t('Fixed'),
      'removed' => $this->t('Removed'),
      'security' => $this->t('Security'),
      'other' => $this->t('Other'),
    ];

    $rendered_sections = [];
    foreach ($sections as $key => $items) {
      if (!empty($items)) {
        $rendered_sections[$key] = [
          'label' => $section_labels[$key] ?? ucfirst($key),
          'items' => $items,
        ];
      }
    }

    return [
      '#theme' => 'changelogify_release',
      '#release' => $changelogify_release,
      '#sections' => $rendered_sections,
      '#attached' => [
        'library' => ['changelogify/public'],
      ],
      '#cache' => [
        'tags' => $changelogify_release->getCacheTags(),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Title callback for release view.
   */
  public function title(ChangelogifyReleaseInterface $changelogify_release): string {
    return $changelogify_release->getTitle();
  }

  /**
   * Builds an excerpt from sections.
   */
  protected function buildExcerpt(array $sections): string {
    $items = [];
    foreach ($sections as $section_items) {
      foreach ($section_items as $item) {
        $items[] = $item['text'] ?? '';
        if (count($items) >= 2) {
          break 2;
        }
      }
    }

    if (empty($items)) {
      return '';
    }

    return implode(' • ', array_filter($items));
  }

}
