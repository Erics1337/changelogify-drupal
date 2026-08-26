<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs idempotent operations and stores privacy-bounded diagnostic history.
 */
final class AiOperationManager {

  private const LOCK_TIMEOUT = 60;

  /**
   * Safe support reference for the most recent operation in this request.
   */
  private ?string $lastOperationId = NULL;

  public function __construct(private readonly SummarizerInterface $summarizer, private readonly ResultValidator $validator, private readonly KeyValueFactoryInterface $keyValue, private readonly LockBackendInterface $lock, private readonly AccountProxyInterface $currentUser, private readonly TimeInterface $time, private readonly LoggerInterface $logger, private readonly QueueFactory $queueFactory, private readonly ?AiOperationHistoryRepository $history = NULL) {}

  /**
   * Reports whether the configured adapter can accept a request.
   */
  public function isAvailable(): bool {
    return $this->summarizer->isAvailable();
  }

  /**
   * Returns selected non-secret provider identity for privileged UI display.
   *
   * @return array{provider: string, model: string}|null
   *   Selected provider and model, or NULL when unresolved.
   */
  public function selectedProviderModel(): ?array {
    return $this->summarizer->selectedProviderModel();
  }

  /**
   * Runs one provider call while recording bounded diagnostic data.
   *
   * @param \Drupal\changelogify_ai\Summarization\SummarizationRequest $request
   *   Redacted generation request.
   * @param string[] $sourceIds
   *   Selected evidence IDs.
   * @param int|null $releaseId
   *   Target release ID, if one exists.
   * @param int|null $revisionId
   *   Target release revision ID, if one exists.
   */
  public function execute(SummarizationRequest $request, array $sourceIds, ?int $releaseId = NULL, ?int $revisionId = NULL): SummarizationResult {
    $this->lastOperationId = $request->idempotencyKey;
    $store = $this->keyValue->get('changelogify_ai.operations');
    $existing = $store->get($request->idempotencyKey);
    $lockName = 'changelogify_ai:' . $request->idempotencyKey;
    $isStale = is_array($existing)
      && ($existing['status'] ?? NULL) === 'running'
      && ($existing['created'] ?? 0) <= $this->time->getRequestTime() - self::LOCK_TIMEOUT
      && $this->lock->lockMayBeAvailable($lockName);
    if ($isStale) {
      $store->delete($request->idempotencyKey);
      $existing = NULL;
    }
    if (is_array($existing) && in_array($existing['status'] ?? NULL, ['running', 'completed', 'cancelled'], TRUE)) {
      throw new \RuntimeException('An equivalent AI operation is already in progress or complete.');
    }
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      throw new \RuntimeException('An equivalent AI operation is running.');
    }
    $operation = [
      'id' => $request->idempotencyKey,
      'actor' => (int) $this->currentUser->id(),
      'release_id' => $releaseId,
      'revision_id' => $revisionId,
      'type' => $request->operation,
      'prompt_version' => $request->promptVersion,
      'synthesis_version' => $request->getSynthesisVersion(),
      'synthesis_stage' => $request->getSynthesisStage(),
      'length_preset' => $request->getLengthPreset(),
      'policy_version' => $request->policyVersion,
      'payload_hash' => hash('sha256', json_encode($request->evidence, JSON_THROW_ON_ERROR)),
      'status' => 'running',
      'created' => $this->time->getRequestTime(),
      'input_tokens' => NULL,
      'output_tokens' => NULL,
    ];
    $this->persist($store, $request->idempotencyKey, $operation);
    try {
      $result = $this->summarizer->summarize($request);
      $this->validator->validate($result, $sourceIds, $request);
      $operation = array_replace($operation, [
        'status' => $result->status,
        'provider_id' => $result->providerId,
        'model_id' => $result->modelId,
        'input_tokens' => $result->inputTokens,
        'output_tokens' => $result->outputTokens,
        'completed' => $this->time->getRequestTime(),
      ]);
      $this->persist($store, $request->idempotencyKey, $operation);
      return new SummarizationResult(
        $result->status,
        $result->items,
        $result->omittedSourceIds,
        $result->warnings,
        $result->providerId,
        $result->modelId,
        $result->inputTokens,
        $result->outputTokens,
        $request->idempotencyKey,
      );
    }
    catch (\Throwable $exception) {
      $operation = array_replace($operation, [
        'status' => 'failed',
        'completed' => $this->time->getRequestTime(),
        'error_class' => $exception::class,
        'error_code' => (new AiFailureMessage())->code($exception),
      ]);
      $this->persist($store, $request->idempotencyKey, $operation);
      $this->logger->error('AI operation @id failed with @exception.', [
        '@id' => $request->idempotencyKey,
        '@exception' => $exception::class,
      ]);
      throw $exception;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Returns the current request's most recent safe support reference.
   */
  public function lastOperationId(): ?string {
    return $this->lastOperationId;
  }

  /**
   * Queues an operation without persisting a provider credential.
   *
   * @param \Drupal\changelogify_ai\Summarization\SummarizationRequest $request
   *   Redacted generation request.
   * @param string[] $sourceIds
   *   Selected evidence IDs.
   * @param int|null $releaseId
   *   Target release ID, if one exists.
   * @param int|null $revisionId
   *   Target release revision ID, if one exists.
   * @param string $queueName
   *   Queue worker plugin ID.
   * @param array<string, mixed> $context
   *   Credential-free queue context.
   */
  public function enqueue(SummarizationRequest $request, array $sourceIds, ?int $releaseId = NULL, ?int $revisionId = NULL, string $queueName = 'changelogify_ai_draft', array $context = []): void {
    $store = $this->keyValue->get('changelogify_ai.operations');
    $existing = $store->get($request->idempotencyKey);
    if (is_array($existing) && in_array($existing['status'] ?? NULL, ['queued', 'running', 'completed'], TRUE)) {
      throw new \RuntimeException('An equivalent AI operation is already in progress or complete.');
    }
    $operation = [
      'id' => $request->idempotencyKey,
      'actor' => (int) $this->currentUser->id(),
      'release_id' => $releaseId,
      'revision_id' => $revisionId,
      'type' => $request->operation,
      'prompt_version' => $request->promptVersion,
      'synthesis_version' => $request->getSynthesisVersion(),
      'synthesis_stage' => $request->getSynthesisStage(),
      'length_preset' => $request->getLengthPreset(),
      'policy_version' => $request->policyVersion,
      'payload_hash' => hash('sha256', json_encode($request->evidence, JSON_THROW_ON_ERROR)),
      'status' => 'queued',
      'created' => $this->time->getRequestTime(),
      'input_tokens' => NULL,
      'output_tokens' => NULL,
    ];
    $this->persist($store, $request->idempotencyKey, $operation);
    $this->queueFactory->get($queueName)->createItem($context + [
      'request' => $request,
      'source_ids' => $sourceIds,
      'release_id' => $releaseId,
      'revision_id' => $revisionId,
      'attempt' => 0,
    ]);
  }

  /**
   * Marks queued work cancelled; a worker checks this before execution.
   */
  public function cancel(string $operationId): void {
    $store = $this->keyValue->get('changelogify_ai.operations');
    $operation = $store->get($operationId);
    if (!is_array($operation) || ($operation['status'] ?? NULL) !== 'queued') {
      throw new \UnexpectedValueException('Only queued operations can be cancelled.');
    }
    $operation['status'] = 'cancelled';
    $operation['completed'] = $this->time->getRequestTime();
    $this->persist($store, $operationId, $operation);
  }

  /**
   * Records an editor's disposition without retaining generated text.
   */
  public function recordDisposition(string $operationId, string $disposition, ?int $revisionId = NULL): void {
    if (!in_array($disposition, ['accepted', 'rejected'], TRUE)) {
      throw new \InvalidArgumentException('Unsupported AI operation disposition.');
    }
    $store = $this->keyValue->get('changelogify_ai.operations');
    $operation = $this->assertCanRecordDisposition($operationId);
    $operation['disposition'] = $disposition;
    $operation['disposed'] = $this->time->getRequestTime();
    if ($revisionId !== NULL) {
      $operation['accepted_revision_id'] = $revisionId;
    }
    $this->persist($store, $operationId, $operation);
  }

  /**
   * Validates that a completed operation still awaits editor disposition.
   *
   * @return array<string, mixed>
   *   The current bounded operation record.
   */
  public function assertCanRecordDisposition(string $operationId): array {
    $operation = $this->keyValue->get('changelogify_ai.operations')->get($operationId);
    if (!is_array($operation) || ($operation['status'] ?? NULL) !== 'completed') {
      throw new \UnexpectedValueException('The AI operation is not complete or no longer exists.');
    }
    if (isset($operation['disposition'])) {
      throw new \UnexpectedValueException('The AI operation was already reviewed.');
    }
    return $operation;
  }

  /**
   * Returns bounded diagnostic state for a privileged operation view.
   */
  public function get(string $operationId): ?array {
    $operation = $this->keyValue->get('changelogify_ai.operations')->get($operationId);
    return is_array($operation) ? $operation : NULL;
  }

  /**
   * Returns privacy-bounded operation records, newest first.
   *
   * @return array<string, array<string, mixed>>
   *   Operation IDs mapped to diagnostic records.
   */
  public function all(): array {
    $operations = array_filter(
      $this->keyValue->get('changelogify_ai.operations')->getAll(),
      'is_array',
    );
    uasort($operations, static fn (array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
    return $operations;
  }

  /**
   * Removes expired diagnostic records without touching releases or evidence.
   */
  public function purge(int $retentionDays): void {
    $store = $this->keyValue->get('changelogify_ai.operations');
    $retentionDays = min(3650, max(1, $retentionDays));
    $cutoff = $this->time->getRequestTime() - ($retentionDays * 86400);
    foreach ($store->getAll() as $key => $operation) {
      if (is_array($operation) && ($operation['created'] ?? 0) < $cutoff) {
        $store->delete($key);
      }
    }
    $this->history?->deleteOlderThan($cutoff);
  }

  /**
   * Keeps temporary state and its privacy-bounded index synchronized.
   */
  private function persist(KeyValueStoreInterface $store, string $id, array $operation): void {
    $operation['updated'] = $this->time->getRequestTime();
    $store->set($id, $operation);
    $this->history?->save($operation);
  }

}
