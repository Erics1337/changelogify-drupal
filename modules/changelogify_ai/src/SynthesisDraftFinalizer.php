<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\ReleaseGeneratorInterface;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use Psr\Log\LoggerInterface;

/**
 * Atomically creates an unpublished draft from a revalidated synthesis job.
 */
final class SynthesisDraftFinalizer {

  private const LOCK_TIMEOUT = 300;

  public function __construct(
    private readonly SynthesisJobManager $jobs,
    private readonly SynthesisEvidenceSelector $evidenceSelector,
    private readonly ReleaseGeneratorInterface $releaseGenerator,
    private readonly ResultValidator $validator,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Finalizes a ready job once; intermediate and legacy jobs are ignored.
   */
  public function finalizeIfReady(string $jobId): ?ChangelogifyReleaseInterface {
    $lockName = "changelogify_ai:synthesis_finalize:{$jobId}";
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return NULL;
    }
    $transaction = NULL;
    try {
      $job = $this->jobs->get($jobId);
      if (!is_array($job) || ($job['status'] ?? NULL) !== 'completed') {
        return NULL;
      }
      $context = $this->context($job['finalization_context'] ?? NULL);
      if ($context === NULL) {
        return NULL;
      }
      $start = new \DateTimeImmutable('@' . $context['start']);
      $end = new \DateTimeImmutable('@' . $context['end']);
      $preview = $this->releaseGenerator->previewRange($start, $end);
      $boundary = $this->evidenceSelector->select($preview->changeSets, $context['exclusions']);
      $this->validateBoundary($job, $context, $boundary);
      $result = $this->jobs->result($jobId);
      $request = new SummarizationRequest(
        SynthesisContract::OPERATION,
        $job['profile'],
        $boundary['evidence'],
        $job['prompt_version'],
        $job['policy_version'],
        $jobId,
        '',
        $job['synthesis_version'],
        SynthesisContract::STAGE_FINAL,
        $job['length_preset'],
      );
      $this->validator->validate($result, array_keys($boundary['evidence']), $request);
      if ($result->status !== 'completed' || $result->items === []) {
        throw new \UnexpectedValueException('The synthesis result is incomplete.');
      }
      $provenance = $this->jobs->provenance($jobId);
      $this->validateProvenance($result->items, $provenance, array_keys($boundary['evidence']));
      $selection = $this->selection($preview->changeSets, array_keys($boundary['evidence']));

      $transaction = $this->database->startTransaction();
      $release = $this->releaseGenerator->generateReleaseFromSelection(
        $start,
        $end,
        $selection,
        $context['options'],
        FALSE,
        $context['allow_evidence_reuse'],
      );
      $this->apply($release, $result->items, $provenance, $job);
      $releaseId = $release->id();
      if (!is_numeric($releaseId)) {
        throw new \UnexpectedValueException('The synthesized release was not assigned an ID.');
      }
      $this->jobs->markFinalized($jobId, (int) $releaseId);
      unset($transaction);
      return $release;
    }
    catch (\Throwable $exception) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      $this->jobs->failFinalization($jobId, $exception);
      $this->logger->error('AI synthesis finalization @id failed with @exception.', [
        '@id' => $jobId,
        '@exception' => $exception::class,
      ]);
      return NULL;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Validates the bounded, credential-free context shape.
   */
  private function context(mixed $context): ?array {
    if ($context === []) {
      return NULL;
    }
    if (!is_array($context)
      || !is_int($context['start'] ?? NULL)
      || !is_int($context['end'] ?? NULL)
      || $context['start'] > $context['end']
      || !is_array($context['options'] ?? NULL)
      || !is_array($context['exclusions'] ?? NULL)
      || !is_string($context['evidence_fingerprint'] ?? NULL)
      || strlen($context['evidence_fingerprint']) !== 64
      || !ctype_xdigit($context['evidence_fingerprint'])
      || !is_bool($context['allow_evidence_reuse'] ?? NULL)) {
      throw new \UnexpectedValueException('The synthesis finalization context is malformed.');
    }
    if (array_diff(array_keys($context['options']), ['title', 'version', 'label_type']) !== []) {
      throw new \UnexpectedValueException('The synthesis release options are invalid.');
    }
    foreach ($context['options'] as $value) {
      if (!is_string($value)) {
        throw new \UnexpectedValueException('The synthesis release options are invalid.');
      }
    }
    return $context;
  }

  /**
   * Requires current evidence, policies, exclusions, and contracts.
   */
  private function validateBoundary(array $job, array $context, array $boundary): void {
    if (!hash_equals($context['evidence_fingerprint'], $boundary['fingerprint'])
      || !hash_equals($job['payload_hash'], hash('sha256', json_encode($boundary['evidence'], JSON_THROW_ON_ERROR)))
      || !hash_equals($job['policy_version'], $boundary['policy_version'])
      || !hash_equals($job['eligibility_version'], $boundary['eligibility_version'])
      || $job['prompt_version'] !== PromptTemplateRegistry::VERSION
      || $job['synthesis_version'] !== SynthesisContract::VERSION) {
      throw new \UnexpectedValueException('The synthesis evidence or settings are stale.');
    }
  }

  /**
   * Rejects incomplete or unresolved transitive provenance.
   */
  private function validateProvenance(array $items, array $provenance, array $sourceIds): void {
    if (($provenance['version'] ?? NULL) !== 2
      || !is_array($provenance['items'] ?? NULL)
      || !is_array($provenance['sources'] ?? NULL)
      || array_keys($provenance['sources']) !== $sourceIds
      || ($provenance['coverage']['considered_source_ids'] ?? NULL) !== $sourceIds) {
      throw new \UnexpectedValueException('The synthesis provenance is stale or incomplete.');
    }
    foreach ($items as $item) {
      $itemProvenance = $provenance['items'][$item->id] ?? NULL;
      if (!is_array($itemProvenance)
        || ($itemProvenance['change_set_ids'] ?? NULL) !== $item->sourceIds
        || array_diff($item->sourceIds, $sourceIds) !== []) {
        throw new \UnexpectedValueException('A synthesized note has unresolved provenance.');
      }
    }
  }

  /**
   * Builds the current deterministic selection needed to create the shell.
   */
  private function selection(array $changeSets, array $sourceIds): array {
    $selection = [];
    $allowed = array_flip($sourceIds);
    foreach ($changeSets as $changeSet) {
      if ($changeSet instanceof ChangeSet && isset($allowed[$changeSet->id])) {
        $selection[$changeSet->id] = $changeSet->suggestedSection;
      }
    }
    if (array_keys($selection) !== $sourceIds) {
      throw new \UnexpectedValueException('The synthesis source evidence is no longer available.');
    }
    return $selection;
  }

  /**
   * Replaces the deterministic shell with validated synthesized sections.
   */
  private function apply(ChangelogifyReleaseInterface $release, array $items, array $provenance, array $job): void {
    $sections = array_fill_keys(['added', 'changed', 'fixed', 'removed', 'security', 'other'], []);
    foreach ($items as $item) {
      if (!isset($sections[$item->section])) {
        throw new \UnexpectedValueException('A synthesized note uses an unsupported release section.');
      }
      $sections[$item->section][] = [
        'id' => $item->id,
        'text' => $item->text,
        'event_ids' => $provenance['items'][$item->id]['event_ids'],
      ];
    }
    $provenance['synthesis'] = [
      'job_id' => $job['id'],
      'prompt_version' => $job['prompt_version'],
      'synthesis_version' => $job['synthesis_version'],
      'policy_version' => $job['policy_version'],
      'eligibility_version' => $job['eligibility_version'],
    ];
    $release->setUnpublished();
    $release->setNewRevision(TRUE);
    $release->setSections($sections);
    $release->setProvenance($provenance);
    $release->setRevisionLogMessage('AI release synthesis finalized from revalidated evidence.');
    $release->save();
  }

}
