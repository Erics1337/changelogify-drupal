<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores representation-specific API ETags after Drupal finalizes caching.
 */
final class ApiEtagSubscriber implements EventSubscriberInterface {

  private const REPRESENTATION_ETAG = 'X-Changelogify-Representation-Etag';

  /**
   * Applies the controller-computed representation validator.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    $etag = $response->headers->get(self::REPRESENTATION_ETAG);
    if ($etag === NULL) {
      return;
    }
    $response->headers->remove(self::REPRESENTATION_ETAG);
    $response->setEtag($etag);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

}
