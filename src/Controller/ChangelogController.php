<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for public changelog pages.
 */
class ChangelogController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $changelogEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('entity_type.manager'),
          $container->get('date.formatter'),
      );
  }

  /**
   * Displays the public changelog listing.
   */
  public function listing(): array {
    $storage = $this->changelogEntityTypeManager->getStorage('changelogify_release');

    $release_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->sort('release_date', 'DESC')
      ->pager(10)
      ->execute();

    $releases = $storage->loadMultiple($release_ids);

    $items = [];
    foreach ($releases as $release) {
      $sections = $release->getSections();
      $excerpt = $this->buildExcerpt($sections);

      $items[] = [
        'release' => $release,
        'date' => $this->dateFormatter->format($release->getReleaseDate(), 'medium'),
        'excerpt' => $excerpt,
      ];
    }

    $build = [
      '#theme' => 'changelogify_release_list',
      '#releases' => $items,
      '#attached' => [
        'library' => ['changelogify/public'],
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
    ];

    (new CacheableMetadata())
      ->addCacheTags($this->changelogEntityTypeManager
        ->getDefinition('changelogify_release')
        ->getListCacheTags())
      ->addCacheContexts(['user.permissions', 'url.query_args:pagers'])
      ->applyTo($build);

    return $build;
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

    $build = [
      '#theme' => 'changelogify_release',
      '#release' => $changelogify_release,
      '#sections' => $rendered_sections,
      '#attached' => [
        'library' => ['changelogify/public'],
      ],
    ];

    CacheableMetadata::createFromObject($changelogify_release)
      ->addCacheContexts(['user.permissions'])
      ->applyTo($build);

    return $build;
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
