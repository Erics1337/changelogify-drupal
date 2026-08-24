<?php

declare(strict_types=1);

namespace Drupal\changelogify\ChangeSet;

/**
 * Aggregates normalized events into coherent deterministic change sets.
 */
interface ChangeSetAggregatorInterface {

  /**
   * Aggregates at most 5,000 ordered events.
   *
   * @param \Drupal\changelogify\Entity\ChangelogifyEventInterface[] $events
   *   Normalized events.
   */
  public function aggregate(array $events): AggregationResult;

}
