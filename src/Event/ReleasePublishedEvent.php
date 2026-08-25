<?php

declare(strict_types=1);

namespace Drupal\changelogify\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Immutable public contract emitted after a release becomes public.
 */
final class ReleasePublishedEvent extends Event {

  public const NAME = 'changelogify.release_published';

  public function __construct(
    public readonly string $releaseUuid,
    public readonly string $canonicalUrl,
    public readonly int $revisionId,
    public readonly string $language,
    public readonly int $publishedAt,
    public readonly string $idempotencyId,
  ) {}

}
