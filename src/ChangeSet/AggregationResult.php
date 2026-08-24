<?php

declare(strict_types=1);

namespace Drupal\changelogify\ChangeSet;

/**
 * Aggregated change sets and intentionally suppressed input events.
 */
final class AggregationResult {

  /**
   * Constructs an aggregation result.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Deterministically ordered change sets.
   * @param array<int, string> $suppressedEvents
   *   Suppressed event IDs keyed to a stable reason.
   */
  public function __construct(
    public readonly array $changeSets,
    public readonly array $suppressedEvents = [],
  ) {
  }

}
