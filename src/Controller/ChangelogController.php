<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\ReleaseSlugManager;
use Drupal\changelogify\PublicReleaseBuilder;
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
    private readonly PublicReleaseBuilder $publicReleaseBuilder,
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
          $container->get(PublicReleaseBuilder::class),
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
      $release = $this->publicReleaseBuilder->translateForPublic($release);
      if ($release === NULL) {
        continue;
      }
      $presentation = $this->publicReleaseBuilder->build($release, [
        'added', 'changed', 'fixed', 'removed', 'security', 'other',
      ]);
      $sections = $release->getSections();
      $excerpt = $this->buildExcerpt($sections);

      $items[] = $presentation + [
        'slug' => $release->getSlug(),
        'excerpt' => $excerpt,
      ];
    }

    $page = $this->requestStack->getCurrentRequest()?->query->get('page');
    $canonicalOptions = ['absolute' => TRUE];
    if (is_scalar($page) && (string) $page !== '') {
      $canonicalOptions['query'] = ['page' => (string) $page];
    }
    $canonical = $this->publicUrl(NULL, $canonicalOptions)->toString();
    $feedLinks = $this->feedDiscoveryLinks();

    $build = [
      '#theme' => 'changelogify_release_list',
      '#releases' => $items,
      '#attached' => [
        'library' => ['changelogify/public'],
        'html_head_link' => array_merge([[['rel' => 'canonical', 'href' => $canonical], TRUE]], $feedLinks),
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
    ];

    (new CacheableMetadata())
      ->addCacheableDependency($this->config('changelogify.settings'))
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
    $presentation = $this->publicReleaseBuilder->build($changelogify_release, [
      'added', 'changed', 'fixed', 'removed', 'security', 'other',
    ]);

    $build = [
      '#theme' => 'changelogify_release',
      '#title' => $presentation['title'],
      '#date' => $this->dateFormatter->format($changelogify_release->getReleaseDate(), 'long'),
      '#date_iso' => $presentation['date_iso'],
      '#version' => $presentation['version'],
      '#sections' => $presentation['sections'],
      '#translation_fallback' => $presentation['translation_fallback'],
      '#language_name' => $presentation['language_name'],
      '#attached' => [
        'library' => ['changelogify/public'],
        'html_head_link' => array_merge([[
          [
            'rel' => 'canonical',
            'href' => $this->publicUrl(
              $changelogify_release->getSlug(),
              ['absolute' => TRUE],
            )->toString(),
          ],
          TRUE,
        ],
        ], $this->feedDiscoveryLinks()),
      ],
    ];

    CacheableMetadata::createFromObject($changelogify_release)
      ->addCacheableDependency($this->config('changelogify.settings'))
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
    $url = $this->publicUrl($release->getSlug())->toString();
    $response = new CacheableRedirectResponse($url, 301);
    $metadata = CacheableMetadata::createFromObject($release)
      ->addCacheableDependency($this->config('changelogify.settings'))
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
      ]);
    return $response->addCacheableDependency($metadata);
  }

  /**
   * Builds a public URL from the current configured base path.
   *
   * Drupal 10 can retain a compiled route generator after a runtime route
   * rebuild. Reading the configuration directly keeps generated URLs aligned
   * with the route subscriber in persistent kernels as well as PHP-FPM.
   */
  private function publicUrl(?string $slug = NULL, array $options = []): Url {
    $configuredPath = (string) $this->config('changelogify.settings')
      ->get('changelog_path');
    $path = '/' . trim($configuredPath ?: '/changelog', '/');
    if ($slug !== NULL) {
      $path .= '/' . rawurlencode($slug);
    }
    return Url::fromUserInput($path, $options);
  }

  /**
   * Returns RSS and Atom discovery links for public changelog pages.
   */
  private function feedDiscoveryLinks(): array {
    $base = rtrim($this->publicUrl(NULL, ['absolute' => TRUE])->toString(), '/');
    return [
      [[
        'rel' => 'alternate',
        'type' => 'application/rss+xml',
        'title' => (string) $this->t('Changelog RSS feed'),
        'href' => $base . '/feed.rss',
      ], TRUE,
      ],
      [[
        'rel' => 'alternate',
        'type' => 'application/atom+xml',
        'title' => (string) $this->t('Changelog Atom feed'),
        'href' => $base . '/feed.atom',
      ], TRUE,
      ],
    ];
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
