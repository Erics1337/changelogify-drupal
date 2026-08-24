<?php

declare(strict_types=1);

namespace Drupal\changelogify\ChangeSet;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;

/**
 * Defines an ordered contributed change-set grouping strategy.
 */
interface ChangeSetGroupingStrategyInterface {

  /**
   * Gets priority; higher strategies run first.
   */
  public function getPriority(): int;

  /**
   * Gets a stable grouping key, or NULL when the strategy does not apply.
   */
  public function getGroupKey(ChangelogifyEventInterface $event): ?string;

  /**
   * Gets the stable kind for groups produced by this strategy.
   */
  public function getKind(): string;

}
