<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

/**
 * Provides discovered Changelogify event sources.
 */
interface EventSourceRegistryInterface {

  /**
   * Gets all sources keyed by source ID.
   *
   * @return \Drupal\changelogify\EventSource\EventSourceInterface[]
   *   Registered sources sorted by ID.
   */
  public function getSources(): array;

  /**
   * Gets a source by ID.
   *
   * @throws \InvalidArgumentException
   *   When no source has the requested ID.
   */
  public function getSource(string $id): EventSourceInterface;

}
