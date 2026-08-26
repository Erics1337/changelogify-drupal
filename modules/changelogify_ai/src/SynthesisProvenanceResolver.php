<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationResult;

/**
 * Resolves hierarchical citations and builds bounded synthesis provenance.
 */
final class SynthesisProvenanceResolver {

  public const MAX_EVENT_SNAPSHOTS = 200;

  /**
   * Builds the durable, provider-text-free index of original evidence.
   */
  public function sourceIndex(array $evidence, array $sourceProvenance = []): array {
    $index = [];
    $snapshotBudget = self::MAX_EVENT_SNAPSHOTS;
    foreach ($evidence as $sourceId => $document) {
      $trusted = is_array($sourceProvenance[$sourceId] ?? NULL)
        ? $sourceProvenance[$sourceId]
        : [];
      $eventIds = $this->scalarIds($trusted['event_ids'] ?? $document['source_event_ids'] ?? []);
      $events = [];
      foreach (($trusted['events'] ?? []) as $event) {
        if ($snapshotBudget === 0 || !is_array($event)) {
          break;
        }
        $events[] = $this->eventSnapshot($event);
        $snapshotBudget--;
      }
      $eventCount = max(
        count($eventIds),
        (int) ($trusted['event_count'] ?? $document['event_count'] ?? count($eventIds)),
      );
      $index[(string) $sourceId] = [
        'change_set_id' => (string) $sourceId,
        'event_ids' => $eventIds,
        'event_count' => $eventCount,
        'evidence_status' => $this->evidenceStatus((string) ($trusted['evidence_status'] ?? $document['evidence_status'] ?? 'available')),
        'events' => $events,
        'snapshots_truncated' => $eventCount > count($events),
      ];
    }
    return $index;
  }

  /**
   * Resolves one round's citations to original source IDs.
   */
  public function resolveSourceIds(array $sourceIds, array $evidence, string $jobId): array {
    $resolved = [];
    foreach ($sourceIds as $sourceId) {
      if (!is_string($sourceId) || !isset($evidence[$sourceId]) || !is_array($evidence[$sourceId])) {
        throw new \UnexpectedValueException('A synthesis citation references unknown evidence.');
      }
      $source = $evidence[$sourceId];
      if (($source['kind'] ?? NULL) !== 'synthesis_candidate') {
        $resolved[] = $sourceId;
        continue;
      }
      if (($source['job_id'] ?? NULL) !== $jobId) {
        throw new \UnexpectedValueException('A synthesis candidate belongs to another job.');
      }
      $originalIds = $source['original_source_ids'] ?? NULL;
      if (!is_array($originalIds) || $originalIds === []) {
        throw new \UnexpectedValueException('A synthesis candidate has broken provenance.');
      }
      foreach ($originalIds as $originalId) {
        if (!is_string($originalId) || $originalId === $sourceId) {
          throw new \UnexpectedValueException('A synthesis candidate has cyclic or invalid provenance.');
        }
        $resolved[] = $originalId;
      }
    }
    return array_values(array_unique($resolved));
  }

  /**
   * Resolves the final result and computes complete server-side coverage.
   */
  public function finalize(
    SummarizationResult $result,
    array $finalEvidence,
    array $sourceIndex,
    string $jobId,
    array $exclusions = [],
  ): array {
    $items = [];
    $itemProvenance = [];
    $cited = [];
    foreach ($result->items as $item) {
      $sourceIds = $this->resolveSourceIds($item->sourceIds, $finalEvidence, $jobId);
      if ($sourceIds === [] || array_diff($sourceIds, array_keys($sourceIndex)) !== []) {
        throw new \UnexpectedValueException('A final synthesis citation cannot be resolved to original evidence.');
      }
      $items[] = new SummarizationItem($item->id, $item->section, $item->text, $sourceIds);
      $cited = array_merge($cited, $sourceIds);
      $itemProvenance[$item->id] = $this->itemProvenance($item, $sourceIds, $sourceIndex);
    }
    $cited = array_values(array_unique($cited));
    $considered = array_keys($sourceIndex);
    $notSurfaced = array_values(array_diff($considered, $cited));
    $editorExcluded = $this->scalarIds($exclusions['editor'] ?? []);
    $policyExcluded = $this->scalarIds($exclusions['policy'] ?? []);
    $coverage = [
      'evidence_considered' => count($considered),
      'evidence_cited' => count($cited),
      'excluded_by_editor' => count($editorExcluded),
      'excluded_by_policy' => count($policyExcluded),
      'eligible_not_surfaced' => count($notSurfaced),
      'considered_source_ids' => $considered,
      'cited_source_ids' => $cited,
      'editor_excluded_source_ids' => $editorExcluded,
      'policy_excluded_source_ids' => $policyExcluded,
      'not_surfaced_source_ids' => $notSurfaced,
    ];
    return [
      'result' => new SummarizationResult(
        $result->status,
        $items,
        $notSurfaced,
        $result->warnings,
        $result->providerId,
        $result->modelId,
        $result->inputTokens,
        $result->outputTokens,
      ),
      'provenance' => [
        'version' => 2,
        'items' => $itemProvenance,
        'sources' => $sourceIndex,
        'coverage' => $coverage,
      ],
      'coverage' => $coverage,
    ];
  }

  /**
   * Builds bounded provenance for one final factual note.
   */
  private function itemProvenance(SummarizationItem $item, array $sourceIds, array $sourceIndex): array {
    $eventIds = [];
    $eventSnapshotIds = [];
    $eventCount = 0;
    $statuses = [];
    foreach ($sourceIds as $sourceId) {
      $source = $sourceIndex[$sourceId];
      $eventIds = array_merge($eventIds, $source['event_ids']);
      $eventCount += $source['event_count'];
      $statuses[] = $source['evidence_status'];
      foreach ($source['events'] as $event) {
        $key = (string) ($event['event_id'] ?? $event['event_uuid'] ?? hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)));
        $eventSnapshotIds[] = $key;
      }
    }
    return [
      'change_set_ids' => $sourceIds,
      'kind' => count($sourceIds) === 1 ? 'ai_synthesized' : 'ai_combined',
      'section' => $item->section,
      'event_ids' => array_values(array_unique($eventIds, SORT_REGULAR)),
      'event_count' => $eventCount,
      'evidence_status' => count(array_unique($statuses)) === 1 ? $statuses[0] : 'partial',
      'event_snapshot_ids' => array_slice(array_values(array_unique($eventSnapshotIds)), 0, self::MAX_EVENT_SNAPSHOTS),
      'snapshots_truncated' => $eventCount > count(array_unique($eventSnapshotIds)),
    ];
  }

  /**
   * Keeps only established evidence lifecycle states.
   */
  private function evidenceStatus(string $status): string {
    return in_array($status, ['available', 'partial', 'expired', 'removed', 'missing', 'invalid'], TRUE)
      ? $status
      : 'partial';
  }

  /**
   * Normalizes source and event identifiers without discarding numeric IDs.
   */
  private function scalarIds(mixed $ids): array {
    if (!is_array($ids)) {
      return [];
    }
    return array_values(array_unique(array_filter(
      $ids,
      static fn (mixed $id): bool => is_string($id) || is_int($id),
    ), SORT_REGULAR));
  }

  /**
   * Retains the small allowlisted event snapshot used by release provenance.
   */
  private function eventSnapshot(array $event): array {
    return array_intersect_key($event, array_flip([
      'event_id', 'event_uuid', 'event_type', 'source', 'occurred_at',
      'evidence_status', 'summary',
    ]));
  }

}
