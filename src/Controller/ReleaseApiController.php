<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\PublicReleaseBuilder;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides the stable, read-only Changelogify public API v1.
 */
final class ReleaseApiController implements ContainerInjectionInterface {

  private const SECTIONS = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

  public function __construct(
    private readonly PublicReleaseBuilder $releaseBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Creates the controller from the service container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PublicReleaseBuilder::class),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns a bounded, deterministic page of published releases.
   */
  public function collection(Request $request): CacheableJsonResponse {
    $limit = min(20, max(1, $request->query->getInt('limit', 10)));
    $offset = min(10_000, max(0, $request->query->getInt('offset', 0)));
    $releases = $this->releaseBuilder->loadPage($limit + 1, $offset);
    $hasMore = count($releases) > $limit;
    $releases = array_slice($releases, 0, $limit);
    $data = [
      'schema' => 'changelogify.release-list.v1',
      'releases' => array_map($this->serialize(...), $releases),
      'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => $hasMore,
      ],
    ];
    return $this->response($data, $releases, $request, TRUE);
  }

  /**
   * Returns one accessible published release by its current public slug.
   */
  public function detail(string $release_slug, Request $request): CacheableJsonResponse {
    $storage = $this->entityTypeManager->getStorage('changelogify_release');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->condition('slug', $release_slug)
      ->range(0, 1)
      ->execute();
    $release = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$release instanceof ChangelogifyReleaseInterface || !$release->access('view')) {
      throw new NotFoundHttpException();
    }
    return $this->response([
      'schema' => 'changelogify.release.v1',
      'release' => $this->serialize($release),
    ], [$release], $request);
  }

  /**
   * Serializes only the documented public release contract.
   */
  private function serialize(ChangelogifyReleaseInterface $release): array {
    $presentation = $this->releaseBuilder->build($release, self::SECTIONS);
    return [
      'uuid' => $release->uuid(),
      'slug' => $release->getSlug(),
      'url' => $this->releaseBuilder
        ->releaseUrl($release->getSlug(), ['absolute' => TRUE])
        ->toString(),
      'title' => $release->getTitle(),
      'version' => $release->getVersion(),
      'language' => $release->language()->getId(),
      'release_date' => gmdate('c', $release->getReleaseDate()),
      'coverage' => [
        'start' => (int) $release->get('date_start')->value > 0
          ? gmdate('c', (int) $release->get('date_start')->value)
          : NULL,
        'end' => (int) $release->get('date_end')->value > 0
          ? gmdate('c', (int) $release->get('date_end')->value)
          : NULL,
      ],
      'sections' => $presentation['sections'],
    ];
  }

  /**
   * Builds a cache-aware JSON response with conditional request validators.
   */
  private function response(array $data, array $releases, Request $request, bool $list = FALSE): CacheableJsonResponse {
    $response = new CacheableJsonResponse($data);
    $changed = array_map(
      static fn (ChangelogifyReleaseInterface $release): int => max(
        $release->getReleaseDate(),
        (int) $release->get('changed')->value,
      ),
      $releases,
    );
    if ($changed !== []) {
      $lastModified = max($changed);
      $response->setLastModified((new \DateTimeImmutable())->setTimestamp($lastModified));
      // Drupal's response finalizer uses Last-Modified as its public ETag.
      // Setting the same validator here enables controller-level 304 handling.
      $response->setEtag((string) $lastModified);
    }
    $metadata = (new CacheableMetadata())
      ->addCacheableDependency($this->configFactory->get('changelogify.settings'))
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
        'url.site',
      ]);
    if ($list) {
      $metadata
        ->addCacheTags($this->entityTypeManager
          ->getDefinition('changelogify_release')
          ->getListCacheTags())
        ->addCacheContexts(['url.query_args:limit', 'url.query_args:offset']);
    }
    foreach ($releases as $release) {
      $metadata->addCacheableDependency($release);
    }
    $response->addCacheableDependency($metadata);
    $response->isNotModified($request);
    return $response;
  }

}
