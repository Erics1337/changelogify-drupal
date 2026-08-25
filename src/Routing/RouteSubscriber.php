<?php

declare(strict_types=1);

namespace Drupal\changelogify\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Applies the configured public changelog path to Changelogify routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $configured_path = (string) $this->configFactory
      ->get('changelogify.settings')
      ->get('changelog_path');
    $base_path = '/' . trim($configured_path ?: '/changelog', '/');

    $collection->get('changelogify.changelog')?->setPath($base_path);
    $collection->get('changelogify.changelog_release')?->setPath($base_path . '/{release_slug}');
    $collection->get('changelogify.changelog_release_legacy')?->setPath($base_path . '/{changelogify_release}');
    $collection->get('changelogify.feed_rss')?->setPath($base_path . '/feed.rss');
    $collection->get('changelogify.feed_atom')?->setPath($base_path . '/feed.atom');
    $collection->get('entity.changelogify_release.canonical')
      ?->setRequirement('_permission', 'manage changelogify releases');
  }

}
