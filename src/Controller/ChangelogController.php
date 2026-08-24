<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\ReleaseSlugManager;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for public changelog pages.
 */
class ChangelogController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $changelogEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ReleaseSlugManager $slugManager,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('entity_type.manager'),
          $container->get('date.formatter'),
          $container->get(ReleaseSlugManager::class),
          $container->get('request_stack'),
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
        'title' => $release->getTitle(),
        'slug' => $release->getSlug(),
        'date' => $this->dateFormatter->format($release->getReleaseDate(), 'medium'),
        'date_iso' => $this->dateFormatter->format($release->getReleaseDate(), 'custom', 'c'),
        'version' => $release->getVersion(),
        'excerpt' => $excerpt,
        'url' => Url::fromRoute('changelogify.changelog_release', [
          'release_slug' => $release->getSlug(),
        ])->toString(),
      ];
    }

    $canonical = Url::fromRoute('changelogify.changelog', [], [
      'absolute' => TRUE,
      'query' => $this->requestStack->getCurrentRequest()?->query->all() ?? [],
    ])->toString();

    $build = [
      '#theme' => 'changelogify_release_list',
      '#releases' => $items,
      '#attached' => [
        'library' => ['changelogify/public'],
        'html_head_link' => [[
          ['rel' => 'canonical', 'href' => $canonical],
          TRUE,
        ],
        ],
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
    ];

    (new CacheableMetadata())
      ->addCacheTags($this->changelogEntityTypeManager
        ->getDefinition('changelogify_release')
        ->getListCacheTags())
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
        'url.query_args:pagers',
      ])
      ->applyTo($build);

    return $build;
  }

  /**
   * Displays a single release.
   */
  public function view(string $release_slug): array|CacheableRedirectResponse {
    $resolved = $this->resolveAccessible($release_slug);
    $changelogify_release = $resolved['release'];
    if ($resolved['historical']) {
      return $this->canonicalRedirect($changelogify_release);
    }
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
          'items' => array_map(
            static fn (array $item): array => ['text' => (string) ($item['text'] ?? '')],
            $items,
          ),
        ];
      }
    }

    $build = [
      '#theme' => 'changelogify_release',
      '#title' => $changelogify_release->getTitle(),
      '#date' => $this->dateFormatter->format($changelogify_release->getReleaseDate(), 'long'),
      '#date_iso' => $this->dateFormatter->format($changelogify_release->getReleaseDate(), 'custom', 'c'),
      '#version' => $changelogify_release->getVersion(),
      '#sections' => $rendered_sections,
      '#attached' => [
        'library' => ['changelogify/public'],
        'html_head_link' => [[
          [
            'rel' => 'canonical',
            'href' => Url::fromRoute('changelogify.changelog_release', [
              'release_slug' => $changelogify_release->getSlug(),
            ], ['absolute' => TRUE])->toString(),
          ],
          TRUE,
        ],
        ],
      ],
    ];

    CacheableMetadata::createFromObject($changelogify_release)
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
      ])
      ->applyTo($build);

    return $build;
  }

  /**
   * Title callback for release view.
   */
  public function title(string $release_slug): string {
    return $this->resolveAccessible($release_slug)['release']->getTitle();
  }

  /**
   * Permanently redirects an accessible legacy numeric release URL.
   */
  public function legacyRedirect(ChangelogifyReleaseInterface $changelogify_release): CacheableRedirectResponse {
    if (!$changelogify_release->access('view', $this->currentUser())) {
      throw new NotFoundHttpException();
    }
    return $this->canonicalRedirect($changelogify_release);
  }

  /**
   * Resolves an accessible current or historical slug without draft leakage.
   */
  private function resolveAccessible(string $slug): array {
    $resolved = $this->slugManager->resolve($slug);
    if ($resolved === NULL || !$resolved['release']->access('view', $this->currentUser())) {
      throw new NotFoundHttpException();
    }
    return $resolved;
  }

  /**
   * Builds a permanent redirect to a release's current public slug.
   */
  private function canonicalRedirect(ChangelogifyReleaseInterface $release): CacheableRedirectResponse {
    $url = Url::fromRoute('changelogify.changelog_release', [
      'release_slug' => $release->getSlug(),
    ])->toString();
    $response = new CacheableRedirectResponse($url, 301);
    $metadata = CacheableMetadata::createFromObject($release)
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
      ]);
    return $response->addCacheableDependency($metadata);
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
