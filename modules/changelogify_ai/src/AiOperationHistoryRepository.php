<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;

/**
 * Persists and queries privacy-bounded operation summaries.
 */
final class AiOperationHistoryRepository {

  public const TABLE = 'changelogify_ai_operation';

  public function __construct(private readonly Connection $database, private readonly TimeInterface $time) {}

  /**
   * Writes one bounded operation summary when the index exists.
   */
  public function save(array $operation): void {
    if (!$this->available() || !is_string($operation['id'] ?? NULL)) {
      return;
    }
    $coverage = is_array($operation['coverage'] ?? NULL) ? $operation['coverage'] : [];
    $created = (int) ($operation['created'] ?? $this->time->getRequestTime());
    $fields = [
      'operation_id' => $operation['id'],
      'actor' => max(0, (int) ($operation['actor'] ?? 0)),
      'submission_key' => $this->nullableString($operation['submission_key'] ?? NULL, 64),
      'operation_type' => $this->boundedString($operation['type'] ?? 'unknown', 64),
      'status' => $this->boundedString($operation['status'] ?? 'unknown', 32),
      'stage' => $this->nullableString($operation['stage'] ?? $operation['synthesis_stage'] ?? NULL, 32),
      'created' => $created,
      'updated' => (int) ($operation['updated'] ?? $operation['completed'] ?? $created),
      'completed' => isset($operation['completed']) ? (int) $operation['completed'] : NULL,
      'release_id' => isset($operation['release_id']) ? (int) $operation['release_id'] : NULL,
      'revision_id' => isset($operation['revision_id']) ? (int) $operation['revision_id'] : NULL,
      'accepted_revision_id' => isset($operation['accepted_revision_id']) ? (int) $operation['accepted_revision_id'] : NULL,
      'provider_id' => $this->nullableString($operation['provider_id'] ?? NULL, 128),
      'model_id' => $this->nullableString($operation['model_id'] ?? NULL, 255),
      'input_tokens' => isset($operation['input_tokens']) ? (int) $operation['input_tokens'] : NULL,
      'output_tokens' => isset($operation['output_tokens']) ? (int) $operation['output_tokens'] : NULL,
      'error_code' => $this->nullableString($operation['error_code'] ?? NULL, 64),
      'profile' => $this->nullableString($operation['profile'] ?? NULL, 64),
      'length_preset' => $this->nullableString($operation['length_preset'] ?? NULL, 32),
      'payload_hash' => $this->nullableString($operation['payload_hash'] ?? NULL, 64),
      'round' => max(0, (int) ($operation['round'] ?? 0)),
      'total_batches' => max(0, (int) ($operation['total_batches'] ?? 0)),
      'completed_batches' => max(0, (int) ($operation['completed_batches'] ?? 0)),
      'retry_count' => max(0, (int) ($operation['retry_count'] ?? 0)),
      'evidence_considered' => max(0, (int) ($coverage['evidence_considered'] ?? 0)),
      'evidence_cited' => max(0, (int) ($coverage['evidence_cited'] ?? 0)),
      'eligible_not_surfaced' => max(0, (int) ($coverage['eligible_not_surfaced'] ?? 0)),
      'disposition' => $this->nullableString($operation['disposition'] ?? NULL, 16),
    ];
    $this->database->merge(self::TABLE)
      ->key('operation_id', $operation['id'])
      ->fields($fields)
      ->execute();
  }

  /**
   * Returns a single summary.
   */
  public function get(string $operationId): ?array {
    if (!$this->available()) {
      return NULL;
    }
    $record = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('operation_id', $operationId)
      ->execute()
      ->fetchAssoc();
    return is_array($record) ? $record : NULL;
  }

  /**
   * Finds reusable, unfinished synthesis work by stable submission key.
   */
  public function findActiveSubmission(string $submissionKey): ?array {
    if (!$this->available() || $submissionKey === '') {
      return NULL;
    }
    $record = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('submission_key', $submissionKey)
      ->condition('status', ['queued', 'running', 'completed'], 'IN')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($record) ? $record : NULL;
  }

  /**
   * Returns one filtered history page, with active work first.
   */
  public function page(array $filters = [], int $limit = 25): array {
    if (!$this->available()) {
      return [];
    }
    $query = $this->database->select(self::TABLE, 'o');
    $query->fields('o');
    if (is_string($filters['status'] ?? NULL) && $filters['status'] !== '') {
      $query->condition('status', $filters['status']);
    }
    if (is_string($filters['type'] ?? NULL) && $filters['type'] !== '') {
      $query->condition('operation_type', $filters['type']);
    }
    if (is_int($filters['since'] ?? NULL) && $filters['since'] > 0) {
      $query->condition('created', $filters['since'], '>=');
    }
    $query->addExpression("CASE WHEN o.status IN ('queued', 'running', 'completed') THEN 0 ELSE 1 END", 'active_sort');
    $query->orderBy('active_sort')->orderBy('created', 'DESC');
    $pager = $query->extend(PagerSelectExtender::class)->limit(max(1, min(100, $limit)));
    return array_map(static fn (object $record): array => (array) $record, $pager->execute()->fetchAll());
  }

  /**
   * Deletes retained summaries older than the supplied timestamp.
   */
  public function deleteOlderThan(int $cutoff): void {
    if ($this->available()) {
      $delete = $this->database->delete(self::TABLE)
        ->condition('created', $cutoff, '<');
      $terminal = $delete->orConditionGroup()
        ->condition('status', ['finalized', 'failed', 'cancelled'], 'IN');
      $completed = $delete->andConditionGroup()
        ->condition('status', 'completed')
        ->condition('operation_type', 'synthesize_release', '<>');
      $terminal->condition($completed);
      $delete->condition($terminal)->execute();
    }
  }

  /**
   * Reports whether the indexed history table is available.
   */
  public function available(): bool {
    return $this->database->schema()->tableExists(self::TABLE);
  }

  /**
   * Converts a scalar into a bounded string.
   */
  private function boundedString(mixed $value, int $length): string {
    return mb_substr(is_scalar($value) ? (string) $value : '', 0, $length);
  }

  /**
   * Converts an empty bounded string to NULL.
   */
  private function nullableString(mixed $value, int $length): ?string {
    $bounded = $this->boundedString($value, $length);
    return $bounded === '' ? NULL : $bounded;
  }

}
