<?php

declare(strict_types=1);

namespace Drupal\changelogify\Controller;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\PublicReleaseBuilder;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves stable RSS and Atom representations of published releases.
 */
final class FeedController implements ContainerInjectionInterface {

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
   * Returns the RSS 2.0 feed.
   */
  public function rss(): CacheableResponse {
    $releases = $this->releaseBuilder->load(20);
    $channelUrl = $this->releaseBuilder->changelogUrl(['absolute' => TRUE])->toString();
    $items = '';
    foreach ($releases as $release) {
      $presentation = $this->releaseBuilder->build($release, self::SECTIONS);
      $url = $this->releaseBuilder->releaseUrl($release->getSlug(), ['absolute' => TRUE])->toString();
      $items .= '<item>'
        . '<title>' . $this->xml($release->getTitle()) . '</title>'
        . '<link>' . $this->xml($url) . '</link>'
        . '<guid isPermaLink="false">urn:uuid:' . $this->xml($release->uuid()) . '</guid>'
        . '<pubDate>' . gmdate(DATE_RSS, $release->getReleaseDate()) . '</pubDate>'
        . '<description>' . $this->xml($this->content($presentation['sections'])) . '</description>'
        . '</item>';
    }
    $updated = $this->latestChanged($releases);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<rss version="2.0"><channel>'
      . '<title>Changelog</title>'
      . '<link>' . $this->xml($channelUrl) . '</link>'
      . '<description>Published changelog releases</description>'
      . '<lastBuildDate>' . gmdate(DATE_RSS, $updated) . '</lastBuildDate>'
      . $items
      . '</channel></rss>';
    return $this->response($xml, 'application/rss+xml; charset=UTF-8', $releases);
  }

  /**
   * Returns the Atom 1.0 feed.
   */
  public function atom(): CacheableResponse {
    $releases = $this->releaseBuilder->load(20);
    $channelUrl = $this->releaseBuilder->changelogUrl(['absolute' => TRUE])->toString();
    $selfUrl = $channelUrl . '/feed.atom';
    $entries = '';
    foreach ($releases as $release) {
      $presentation = $this->releaseBuilder->build($release, self::SECTIONS);
      $url = $this->releaseBuilder->releaseUrl($release->getSlug(), ['absolute' => TRUE])->toString();
      $entries .= '<entry>'
        . '<title>' . $this->xml($release->getTitle()) . '</title>'
        . '<id>urn:uuid:' . $this->xml($release->uuid()) . '</id>'
        . '<link rel="alternate" href="' . $this->xml($url) . '"/>'
        . '<published>' . gmdate(DATE_ATOM, $release->getReleaseDate()) . '</published>'
        . '<updated>' . gmdate(DATE_ATOM, $this->changed($release)) . '</updated>'
        . '<content type="html">' . $this->xml($this->content($presentation['sections'])) . '</content>'
        . '</entry>';
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<feed xmlns="http://www.w3.org/2005/Atom">'
      . '<title>Changelog</title>'
      . '<id>' . $this->xml($channelUrl) . '</id>'
      . '<link rel="alternate" href="' . $this->xml($channelUrl) . '"/>'
      . '<link rel="self" href="' . $this->xml($selfUrl) . '"/>'
      . '<updated>' . gmdate(DATE_ATOM, $this->latestChanged($releases)) . '</updated>'
      . $entries
      . '</feed>';
    return $this->response($xml, 'application/atom+xml; charset=UTF-8', $releases);
  }

  /**
   * Creates a cache-aware XML response.
   */
  private function response(string $xml, string $contentType, array $releases): CacheableResponse {
    $response = new CacheableResponse($xml, 200, ['Content-Type' => $contentType]);
    $metadata = (new CacheableMetadata())
      ->addCacheableDependency($this->configFactory->get('changelogify.settings'))
      ->addCacheTags($this->entityTypeManager
        ->getDefinition('changelogify_release')
        ->getListCacheTags())
      ->addCacheContexts([
        'languages:language_content',
        'languages:language_interface',
        'user.permissions',
        'url.site',
      ]);
    foreach ($releases as $release) {
      $metadata->addCacheableDependency($release);
    }
    $response->addCacheableDependency($metadata);
    return $response;
  }

  /**
   * Converts safe release sections into escaped feed HTML content.
   */
  private function content(array $sections): string {
    $html = '';
    foreach ($sections as $section) {
      $html .= '<h2>' . $this->html((string) $section['label']) . '</h2><ul>';
      foreach ($section['items'] as $item) {
        $html .= '<li>' . $this->html((string) $item['text']) . '</li>';
      }
      $html .= '</ul>';
    }
    return $html;
  }

  /**
   * Returns a stable last-modified timestamp for one release.
   */
  private function changed(ChangelogifyReleaseInterface $release): int {
    return max($release->getReleaseDate(), (int) $release->get('changed')->value);
  }

  /**
   * Returns the latest feed update or a stable empty-feed fallback.
   */
  private function latestChanged(array $releases): int {
    if ($releases === []) {
      return 0;
    }
    return max(array_map($this->changed(...), $releases));
  }

  /**
   * Escapes one value for XML text or attribute context.
   */
  private function xml(string $value): string {
    return htmlspecialchars($this->stripInvalidXmlCharacters($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Escapes feed content for an HTML fragment embedded in XML.
   */
  private function html(string $value): string {
    return htmlspecialchars($this->stripInvalidXmlCharacters($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Removes characters XML 1.0 cannot represent.
   */
  private function stripInvalidXmlCharacters(string $value): string {
    return preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
  }

}
