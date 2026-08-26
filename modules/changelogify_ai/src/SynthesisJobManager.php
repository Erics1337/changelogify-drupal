<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizerInterface;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates durable, idempotent, single-request release synthesis jobs.
 */
final class SynthesisJobManager {

  private const STORE = 'changelogify_ai.synthesis_jobs';
  private const LOCK_TIMEOUT = 300;

  public function __construct(
    private readonly SummarizerInterface $summarizer,
    private readonly ResultValidator $validator,
    private readonly SynthesisProvenanceResolver $provenanceResolver,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ?AiOperationHistoryRepository $history = NULL,
  ) {}

  /**
   * Creates a synthesis job and returns its ID for legacy callers.
   */
  public function start(
    array $evidence,
    string $profile,
    string $lengthPreset,
    string $promptVersion,
    string $policyVersion,
    string $eligibilityVersion,
    string $instructions = '',
    array $sourceProvenance = [],
    array $coverageExclusions = [],
    array $finalizationContext = [],
  ): string {
    return $this->startResult(
      $evidence,
      $profile,
      $lengthPreset,
      $promptVersion,
      $policyVersion,
      $eligibilityVersion,
      $instructions,
      $sourceProvenance,
      $coverageExclusions,
      $finalizationContext,
    )->jobId;
  }

  /**
   * Creates one durable job containing the exact reviewed evidence request.
   */
  public function startResult(
    array $evidence,
    string $profile,
    string $lengthPreset,
    string $promptVersion,
    string $policyVersion,
    string $eligibilityVersion,
    string $instructions = '',
    array $sourceProvenance = [],
    array $coverageExclusions = [],
    array $finalizationContext = [],
    int $actor = 0,
    string $submissionKey = '',
  ): SynthesisStartResult {
    $lockName = $submissionKey === '' ? '' : 'changelogify_ai:synthesis_submission:' . $submissionKey;
    if ($lockName !== '' && !$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      throw new \RuntimeException('An equivalent synthesis submission is being created.');
    }
    try {
      return $this->startUnlocked(
        $evidence,
        $profile,
        $lengthPreset,
        $promptVersion,
        $policyVersion,
        $eligibilityVersion,
        $instructions,
        $sourceProvenance,
        $coverageExclusions,
        $finalizationContext,
        $actor,
        $submissionKey,
      );
    }
    finally {
      if ($lockName !== '') {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Creates or safely reuses a synthesis job under the submission lock.
   */
  private function startUnlocked(
    array $evidence,
    string $profile,
    string $lengthPreset,
    string $promptVersion,
    string $policyVersion,
    string $eligibilityVersion,
    string $instructions,
    array $sourceProvenance,
    array $coverageExclusions,
    array $finalizationContext,
    int $actor,
    string $submissionKey,
  ): SynthesisStartResult {
    if ($evidence === []) {
      throw new \UnexpectedValueException('Release synthesis requires eligible evidence.');
    }
    SynthesisContract::validateRequest(
      SynthesisContract::OPERATION,
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      $lengthPreset,
    );
    if (!$this->summarizer->isAvailable()) {
      throw new \RuntimeException('The configured summarizer is unavailable.');
    }
    $store = $this->store();
    if ($submissionKey !== '') {
      $indexed = $this->history?->findActiveSubmission($submissionKey);
      if (is_array($indexed) && is_array($store->get((string) $indexed['operation_id']))) {
        return new SynthesisStartResult((string) $indexed['operation_id'], TRUE);
      }
      foreach ($store->getAll() as $existingId => $candidate) {
        if (is_array($candidate)
          && ($candidate['submission_key'] ?? '') === $submissionKey
          && in_array($candidate['status'] ?? NULL, ['prepared', 'running', 'completed'], TRUE)) {
          return new SynthesisStartResult((string) $existingId, TRUE);
        }
      }
    }
    $jobId = hash('sha256', json_encode([
      'evidence' => $evidence,
      'profile' => $profile,
      'length_preset' => $lengthPreset,
      'prompt_version' => $promptVersion,
      'policy_version' => $policyVersion,
      'eligibility_version' => $eligibilityVersion,
      'instructions' => $instructions,
      'source_provenance' => $sourceProvenance,
      'coverage_exclusions' => $coverageExclusions,
      'finalization_context' => $finalizationContext,
      'synthesis_version' => SynthesisContract::VERSION,
    ], JSON_THROW_ON_ERROR));
    $existing = $store->get($jobId);
    if (is_array($existing) && in_array($existing['status'] ?? NULL, ['prepared', 'running', 'completed'], TRUE)) {
      return new SynthesisStartResult($jobId, TRUE);
    }
    if (is_array($existing)) {
      $baseId = $jobId;
      $attempt = 1;
      do {
        $jobId = hash('sha256', $baseId . ':' . $attempt++);
      } while (is_array($store->get($jobId)));
    }

    $job = [
      'id' => $jobId,
      'type' => SynthesisContract::OPERATION,
      'status' => 'prepared',
      'stage' => SynthesisContract::STAGE_FINAL,
      'created' => $this->time->getRequestTime(),
      'updated' => $this->time->getRequestTime(),
      'actor' => max(0, $actor),
      'submission_key' => $submissionKey === '' ? NULL : $submissionKey,
      'profile' => $profile,
      'length_preset' => $lengthPreset,
      'prompt_version' => $promptVersion,
      'synthesis_version' => SynthesisContract::VERSION,
      'policy_version' => $policyVersion,
      'eligibility_version' => $eligibilityVersion,
      'instructions' => $instructions,
      'evidence' => $evidence,
      'source_index' => $this->provenanceResolver->sourceIndex($evidence, $sourceProvenance),
      'coverage_exclusions' => $coverageExclusions,
      'finalization_context' => $finalizationContext,
      'payload_hash' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
      'attempt_count' => 0,
      'retry_count' => 0,
      'input_tokens' => 0,
      'output_tokens' => 0,
    ];
    $this->persist($store, $jobId, $job);
    return new SynthesisStartResult($jobId, FALSE);
  }

  /**
   * Sends all reviewed evidence to the configured provider exactly once.
   */
  public function process(string $jobId): void {
    $lockName = "changelogify_ai:synthesis:{$jobId}";
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return;
    }
    try {
      $store = $this->store();
      $job = $store->get($jobId);
      $nonProcessable = ['running', 'completed', 'finalized', 'failed', 'cancelled'];
      if (!is_array($job) || in_array($job['status'] ?? NULL, $nonProcessable, TRUE)) {
        return;
      }
      if (!is_array($job['evidence'] ?? NULL) || $job['evidence'] === []) {
        $this->fail($job, new \UnexpectedValueException('The synthesis evidence is unavailable.'));
        return;
      }
      $job['status'] = 'running';
      $job['attempt_count'] = 1;
      $this->persist($store, $jobId, $job);
      try {
        $request = $this->request($job);
        $result = $this->summarizer->summarize($request);
        $this->validator->validate($result, array_keys($job['evidence']), $request);
        if ($result->status !== 'completed' || $result->items === []) {
          throw new \UnexpectedValueException('The provider did not return a completed release summary.');
        }
        $current = $store->get($jobId);
        if (is_array($current) && ($current['status'] ?? NULL) === 'cancelled') {
          return;
        }
        $resolved = $this->provenanceResolver->finalize(
          $result,
          $job['evidence'],
          $job['source_index'],
          $job['coverage_exclusions'],
        );
        $job['final_result'] = $this->normalizeResult($resolved['result']);
        $job['provenance'] = $resolved['provenance'];
        $job['coverage'] = $resolved['coverage'];
        $job['status'] = 'completed';
        $job['completed'] = $this->time->getRequestTime();
        $job['input_tokens'] = $result->inputTokens ?? 0;
        $job['output_tokens'] = $result->outputTokens ?? 0;
        $job['provider_id'] = $result->providerId;
        $job['model_id'] = $result->modelId;
        $this->cleanup($job);
        $this->persist($store, $jobId, $job);
      }
      catch (\Throwable $exception) {
        $this->fail($job, $exception);
      }
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Cancels prepared or running work; an in-flight response is discarded.
   */
  public function cancel(string $jobId): void {
    $store = $this->store();
    $job = $store->get($jobId);
    if (!is_array($job) || !in_array($job['status'] ?? NULL, ['prepared', 'running'], TRUE)) {
      throw new \UnexpectedValueException('Only prepared or running synthesis jobs can be cancelled.');
    }
    $job['status'] = 'cancelled';
    $job['completed'] = $this->time->getRequestTime();
    $this->cleanup($job);
    $this->persist($store, $jobId, $job);
  }

  /**
   * Returns complete internal state for a trusted caller or finalizer.
   */
  public function get(string $jobId): ?array {
    $job = $this->store()->get($jobId);
    return is_array($job) ? $job : NULL;
  }

  /**
   * Returns a completed final result without temporary evidence.
   */
  public function result(string $jobId): SummarizationResult {
    $job = $this->get($jobId);
    if (!is_array($job) || ($job['status'] ?? NULL) !== 'completed' || !is_array($job['final_result'] ?? NULL)) {
      throw new \UnexpectedValueException('The synthesis job has no completed result.');
    }
    return $this->denormalizeResult($job['final_result']);
  }

  /**
   * Returns privacy-bounded job summaries, newest first.
   */
  public function all(): array {
    $summaries = [];
    foreach ($this->store()->getAll() as $id => $job) {
      if (!is_array($job)) {
        continue;
      }
      $summaries[$id] = array_intersect_key($job, array_flip([
        'id', 'type', 'status', 'created', 'updated', 'completed', 'finalized',
        'actor', 'submission_key', 'stage', 'profile', 'length_preset',
        'prompt_version', 'synthesis_version', 'policy_version',
        'eligibility_version', 'payload_hash', 'attempt_count', 'retry_count',
        'input_tokens', 'output_tokens', 'provider_id', 'model_id',
        'error_code', 'error_class', 'release_id', 'coverage',
      ]));
    }
    uasort($summaries, static fn (array $left, array $right): int => ($right['created'] ?? 0) <=> ($left['created'] ?? 0));
    return $summaries;
  }

  /**
   * Removes expired terminal job metadata and completed results.
   */
  public function purge(int $retentionDays): void {
    $retentionDays = min(3650, max(1, $retentionDays));
    $cutoff = $this->time->getRequestTime() - ($retentionDays * 86400);
    $store = $this->store();
    foreach ($store->getAll() as $id => $job) {
      if (is_array($job)
        && in_array($job['status'] ?? NULL, ['completed', 'finalized', 'failed', 'cancelled'], TRUE)
        && ($job['created'] ?? 0) < $cutoff) {
        $store->delete($id);
      }
    }
    $this->history?->deleteOlderThan($cutoff);
  }

  /**
   * Returns resolved, bounded provenance for a completed job.
   */
  public function provenance(string $jobId): array {
    $job = $this->get($jobId);
    if (!is_array($job) || ($job['status'] ?? NULL) !== 'completed' || !is_array($job['provenance'] ?? NULL)) {
      throw new \UnexpectedValueException('The synthesis job has no completed provenance.');
    }
    return $job['provenance'];
  }

  /**
   * Marks a completed job finalized inside the caller's release transaction.
   */
  public function markFinalized(string $jobId, int $releaseId): void {
    $job = $this->get($jobId);
    if (!is_array($job) || ($job['status'] ?? NULL) !== 'completed') {
      throw new \UnexpectedValueException('Only a completed synthesis job can be finalized.');
    }
    $job['status'] = 'finalized';
    $job['release_id'] = $releaseId;
    $job['finalized'] = $this->time->getRequestTime();
    unset($job['finalization_context'], $job['final_result'], $job['provenance']);
    $this->persist($this->store(), $jobId, $job);
  }

  /**
   * Records a safe terminal finalization failure and removes generated text.
   */
  public function failFinalization(string $jobId, \Throwable $exception): void {
    $job = $this->get($jobId);
    if (!is_array($job) || ($job['status'] ?? NULL) !== 'completed') {
      return;
    }
    unset($job['final_result'], $job['provenance']);
    $this->fail($job, $exception);
  }

  /**
   * Creates the immutable, provider-visible single synthesis request.
   */
  private function request(array $job): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      $job['profile'],
      $this->providerEvidence($job['evidence']),
      $job['prompt_version'],
      $job['policy_version'],
      $job['id'],
      $job['instructions'],
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      $job['length_preset'],
    );
  }

  /**
   * Removes server-only metadata before provider use.
   */
  private function providerEvidence(array $evidence): array {
    $providerEvidence = [];
    foreach ($evidence as $sourceId => $document) {
      $providerEvidence[$sourceId] = $document;
      unset(
        $providerEvidence[$sourceId]['job_id'],
        $providerEvidence[$sourceId]['original_source_ids'],
      );
    }
    return $providerEvidence;
  }

  /**
   * Records a terminal failure without provider text or temporary evidence.
   */
  private function fail(array $job, \Throwable $exception): void {
    $job['status'] = 'failed';
    $job['completed'] = $this->time->getRequestTime();
    $job['error_class'] = $exception::class;
    $job['error_code'] = (new AiFailureMessage())->code($exception);
    $this->cleanup($job);
    $this->persist($this->store(), $job['id'], $job);
    $this->logger->error('AI synthesis job @id failed with @exception.', [
      '@id' => $job['id'],
      '@exception' => $exception::class,
    ]);
  }

  /**
   * Removes temporary provider inputs at every terminal state.
   */
  private function cleanup(array &$job): void {
    unset(
      $job['evidence'],
      $job['instructions'],
      $job['source_index'],
      $job['coverage_exclusions'],
    );
    if (($job['status'] ?? NULL) !== 'completed' || ($job['finalization_context'] ?? []) === []) {
      unset($job['finalization_context']);
    }
  }

  /**
   * Converts a value result into key-value-safe scalar arrays.
   */
  private function normalizeResult(SummarizationResult $result): array {
    return [
      'status' => $result->status,
      'items' => array_map(static fn (SummarizationItem $item): array => [
        'id' => $item->id,
        'section' => $item->section,
        'text' => $item->text,
        'source_ids' => $item->sourceIds,
      ], $result->items),
      'omitted_source_ids' => $result->omittedSourceIds,
      'warnings' => $result->warnings,
      'provider_id' => $result->providerId,
      'model_id' => $result->modelId,
      'input_tokens' => $result->inputTokens,
      'output_tokens' => $result->outputTokens,
    ];
  }

  /**
   * Restores the provider-neutral result used by the finalizer.
   */
  private function denormalizeResult(array $result): SummarizationResult {
    $items = array_map(static fn (array $item): SummarizationItem => new SummarizationItem(
      $item['id'],
      $item['section'],
      $item['text'],
      $item['source_ids'],
    ), $result['items']);
    return new SummarizationResult(
      $result['status'],
      $items,
      $result['omitted_source_ids'],
      $result['warnings'],
      $result['provider_id'],
      $result['model_id'],
      $result['input_tokens'],
      $result['output_tokens'],
    );
  }

  /**
   * Keeps durable job state and its bounded history index synchronized.
   */
  private function persist(KeyValueStoreInterface $store, string $jobId, array $job): void {
    $job['updated'] = $this->time->getRequestTime();
    $store->set($jobId, $job);
    $this->history?->save($job);
  }

  /**
   * Returns the synthesis job key-value collection.
   */
  private function store(): KeyValueStoreInterface {
    return $this->keyValue->get(self::STORE);
  }

}
