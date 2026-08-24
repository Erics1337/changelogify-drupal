<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\ChangeSet\ChangeSet;

/**
 * Immutable, non-persisted preview of candidate release change sets.
 */
final class ReleasePreview {

  /**
   * Constructs a release preview.
   *
   * @param int $startTimestamp
   *   Inclusive release-window start timestamp.
   * @param int $endTimestamp
   *   Inclusive release-window end timestamp.
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Candidate change sets in deterministic order.
   * @param array $coverage
   *   Coverage, overlap, and reused-evidence analysis.
   */
  public function __construct(
    public readonly int $startTimestamp,
    public readonly int $endTimestamp,
    public readonly array $changeSets,
    public readonly array $coverage = [],
  ) {
  }

  /**
   * Returns form-safe preview data without persisting entities.
   */
  public function toArray(): array {
    return [
      'start' => $this->startTimestamp,
      'end' => $this->endTimestamp,
      'change_sets' => array_map(
        static fn (ChangeSet $changeSet): array => [
          'id' => $changeSet->id,
          'kind' => $changeSet->kind,
          'start' => $changeSet->startTimestamp,
          'end' => $changeSet->endTimestamp,
          'source' => (string) ($changeSet->summaryContext['source'] ?? ''),
          'message' => (string) ($changeSet->summaryContext['message'] ?? ''),
          'suggested_section' => $changeSet->suggestedSection,
          'evidence_count' => count($changeSet->sourceEventIds),
        ],
        $this->changeSets,
      ),
      'coverage' => $this->coverage,
    ];
  }

}
