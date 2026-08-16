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
    $collection->get('changelogify.changelog_release')?->setPath($base_path . '/{changelogify_release}');
  }

}
