<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders structured release content on administrative entity routes.
 */
final class ChangelogifyReleaseViewBuilder extends EntityViewBuilder implements TrustedCallbackInterface {

  public function __construct(
    EntityTypeInterface $entityType,
    EntityRepositoryInterface $entityRepository,
    LanguageManagerInterface $languageManager,
    Registry $themeRegistry,
    EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct(
      $entityType,
      $entityRepository,
      $languageManager,
      $themeRegistry,
      $entityDisplayRepository,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    assert($entity instanceof ChangelogifyReleaseInterface);
    $sections = $this->sections($entity);
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['changelogify-admin-release']],
      'metadata' => $this->metadata($entity),
      'actions' => $this->actions($entity),
      'content' => $sections !== [] ? $sections : [
        '#type' => 'container',
        '#attributes' => ['class' => ['changelogify-admin-release__empty']],
        'message' => [
          '#markup' => $this->t('This release does not contain any release items yet.'),
        ],
      ],
    ];
    CacheableMetadata::createFromObject($entity)
      ->addCacheContexts(['user.permissions', 'languages:language_interface'])
      ->applyTo($build);
    return $build;
  }

  /**
   * Builds editor-facing release metadata.
   */
  private function metadata(ChangelogifyReleaseInterface $release): array {
    $rows = [
      [$this->t('Editorial state'), ucfirst(str_replace('_', ' ', $release->getEditorialState()))],
      [$this->t('Release date'), $this->dateFormatter->format($release->getReleaseDate(), 'long')],
      [$this->t('Release window'), $this->t('@start through @end', [
        '@start' => $this->formatBoundary((int) $release->get('date_start')->value),
        '@end' => $this->formatBoundary((int) $release->get('date_end')->value),
      ]),
      ],
      [$this->t('Public slug'), $release->getSlug() ?: $this->t('Not assigned')],
    ];
    if (!empty($release->getVersion())) {
      array_splice($rows, 1, 0, [[
        $this->t('Version'),
        $release->getVersion(),
      ],
      ]);
    }
    return [
      '#type' => 'table',
      '#caption' => $this->t('Release details'),
      '#rows' => $rows,
      '#attributes' => ['class' => ['changelogify-admin-release__metadata']],
    ];
  }

  /**
   * Builds permission-aware contextual actions.
   */
  private function actions(ChangelogifyReleaseInterface $release): array {
    $links = [];
    if ($release->access('update')) {
      $links['edit'] = [
        'title' => $this->t('Edit release'),
        'url' => $release->toUrl('edit-form'),
      ];
      $links['state'] = [
        'title' => $release->isPublished()
          ? $this->t('Unpublish or archive')
          : $this->t('Change editorial state'),
        'url' => $release->toUrl('edit-form', ['fragment' => 'edit-editorial-state-wrapper']),
      ];
    }
    if ($release->isPublished() && $release->getSlug() !== '') {
      $links['public'] = [
        'title' => $this->t('View public release'),
        'url' => Url::fromRoute('changelogify.changelog_release', [
          'release_slug' => $release->getSlug(),
        ]),
      ];
    }
    if ($release->access('view all revisions')) {
      $links['revisions'] = [
        'title' => $this->t('Revisions'),
        'url' => $release->toUrl('version-history'),
      ];
    }
    $links['provenance'] = [
      'title' => $this->t('Source evidence'),
      'url' => Url::fromRoute('changelogify.release_provenance', [
        'changelogify_release' => $release->id(),
      ]),
    ];
    if ($release->access('delete')) {
      $links['delete'] = [
        'title' => $this->t('Delete'),
        'url' => $release->toUrl('delete-form'),
      ];
    }
    return [
      '#type' => 'operations',
      '#links' => $links,
      '#attributes' => ['class' => ['changelogify-admin-release__actions']],
    ];
  }

  /**
   * Builds categorized release items.
   */
  private function sections(ChangelogifyReleaseInterface $release): array {
    $labels = [
      'added' => $this->t('Added'),
      'changed' => $this->t('Changed'),
      'fixed' => $this->t('Fixed'),
      'removed' => $this->t('Removed'),
      'security' => $this->t('Security'),
      'other' => $this->t('Other'),
    ];
    $build = [];
    foreach ($release->getSections() as $section => $items) {
      if ($items === []) {
        continue;
      }
      $build[$section] = [
        '#theme' => 'item_list',
        '#title' => $labels[$section] ?? ucfirst($section),
        '#items' => array_map(
          static fn (array $item): string => (string) ($item['text'] ?? ''),
          $items,
        ),
        '#attributes' => [
          'class' => [
            'changelogify-admin-release__section',
            'changelogify-admin-release__section--' . $section,
          ],
        ],
      ];
    }
    return $build;
  }

  /**
   * Formats stored window boundaries safely.
   */
  private function formatBoundary(int $timestamp): string {
    return $timestamp === 0
      ? $this->t('Beginning of recorded history')->__toString()
      : $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i:s T');
  }

}
