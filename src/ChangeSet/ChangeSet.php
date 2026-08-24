<?php

declare(strict_types=1);

namespace Drupal\changelogify\ChangeSet;

/**
 * Immutable coherent group of normalized source events.
 */
final class ChangeSet {

  /**
   * Constructs a coherent change set.
   *
   * @param string $id
   *   Deterministic change-set ID.
   * @param string $kind
   *   Stable grouping kind.
   * @param int $startTimestamp
   *   Earliest included event timestamp.
   * @param int $endTimestamp
   *   Latest included event timestamp.
   * @param int[] $sourceEventIds
   *   Event entity IDs included exactly once in this set.
   * @param string $suggestedSection
   *   Suggested release section.
   * @param array<string, mixed> $summaryContext
   *   Stable context for release drafting.
   * @param array<string, mixed> $provenance
   *   Technical grouping provenance.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $kind,
    public readonly int $startTimestamp,
    public readonly int $endTimestamp,
    public readonly array $sourceEventIds,
    public readonly string $suggestedSection,
    public readonly array $summaryContext,
    public readonly array $provenance,
  ) {
  }

}
