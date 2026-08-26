<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds the safe editor-facing synthesis status contract.
 */
final class SynthesisStatusBuilder {

  use StringTranslationTrait;

  public function __construct(private readonly AiQueueHealth $queueHealth, private readonly DateFormatterInterface $dateFormatter) {}

  /**
   * Returns a privacy-bounded status payload.
   */
  public function build(array $job, bool $canCancel = FALSE, bool $canConfigureProcessing = FALSE): array {
    $status = (string) ($job['status'] ?? 'unknown');
    $health = $this->queueHealth->status((int) ($job['created'] ?? 0));
    $state = $this->state($job, $health['delayed']);
    $terminal = in_array($status, ['finalized', 'failed', 'cancelled'], TRUE);
    $releaseId = isset($job['release_id']) ? (int) $job['release_id'] : 0;
    $coverage = is_array($job['coverage'] ?? NULL) ? $job['coverage'] : [];
    return [
      'id' => substr((string) $job['id'], 0, 16),
      'status' => $status,
      'state' => $state,
      'label' => $this->label($state),
      'message' => $this->message($state, (string) ($job['error_code'] ?? ''), $health),
      'terminal' => $terminal,
      'delayed' => (bool) $health['delayed'],
      'progress' => [
        'completed' => max(0, (int) ($job['completed_batches'] ?? 0)),
        'total' => max(1, (int) ($job['total_batches'] ?? 1)),
        'round' => max(0, (int) ($job['round'] ?? 0)),
      ],
      'coverage' => [
        'considered' => max(0, (int) ($coverage['evidence_considered'] ?? 0)),
        'cited' => max(0, (int) ($coverage['evidence_cited'] ?? 0)),
        'not_surfaced' => max(0, (int) ($coverage['eligible_not_surfaced'] ?? 0)),
      ],
      'queue' => [
        'visible' => in_array($state, ['waiting', 'delayed'], TRUE),
        'state' => $health['processor_state'],
        'label' => $this->queueLabel($health['processor_state']),
        'badge' => $this->queueBadge($health['processor_state']),
        'summary' => $this->queueSummary($health),
        'queued_steps' => max(0, (int) $health['queued_count']),
        'last_activity' => $this->timeLabel((int) $health['last_activity']),
        'next_run' => $this->nextRunLabel($health),
        'processing_url' => $canConfigureProcessing
          ? Url::fromRoute('changelogify_ai.settings')->toString()
          : NULL,
      ],
      'details' => [
        'profile' => (string) ($job['profile'] ?? ''),
        'length' => (string) ($job['length_preset'] ?? ''),
        'provider' => (string) ($job['provider_id'] ?? ''),
        'model' => (string) ($job['model_id'] ?? ''),
        'input_tokens' => max(0, (int) ($job['input_tokens'] ?? 0)),
        'output_tokens' => max(0, (int) ($job['output_tokens'] ?? 0)),
        'prompt_version' => (string) ($job['prompt_version'] ?? ''),
        'policy_version' => (string) ($job['policy_version'] ?? ''),
        'eligibility_version' => (string) ($job['eligibility_version'] ?? ''),
        'failure_code' => (string) ($job['error_code'] ?? ''),
      ],
      'can_cancel' => $canCancel && in_array($status, ['queued', 'running'], TRUE),
      'cancel_url' => $canCancel && in_array($status, ['queued', 'running'], TRUE)
        ? Url::fromRoute('changelogify_ai.cancel_operation', ['operation_id' => $job['id']])->toString()
        : NULL,
      'release_url' => $releaseId > 0
        ? Url::fromRoute('entity.changelogify_release.edit_form', ['changelogify_release' => $releaseId])->toString()
        : NULL,
      'provenance_url' => $releaseId > 0
        ? Url::fromRoute('changelogify.release_provenance', ['changelogify_release' => $releaseId])->toString()
        : NULL,
      'history_url' => Url::fromRoute('changelogify_ai.operation_history')->toString(),
      'generate_url' => Url::fromRoute('changelogify.generate_release')->toString(),
    ];
  }

  /**
   * Maps internal job state to an editorial stage.
   */
  private function state(array $job, bool $delayed): string {
    return match ($job['status'] ?? 'unknown') {
      'queued' => $delayed ? 'delayed' : 'waiting',
      'running' => ((int) ($job['round'] ?? 0)) > 0 || ($job['stage'] ?? '') === 'intermediate'
        ? 'consolidating'
        : 'analyzing',
      'completed' => 'finalizing',
      'finalized' => 'ready',
      'failed' => 'failed',
      'cancelled' => 'cancelled',
      default => 'unknown',
    };
  }

  /**
   * Returns the translated stage label.
   */
  private function label(string $state): string {
    return (string) match ($state) {
      'waiting' => $this->t('Waiting for background processing'),
      'delayed' => $this->t('Background processing is delayed'),
      'analyzing' => $this->t('Analyzing evidence'),
      'consolidating' => $this->t('Consolidating summaries'),
      'finalizing' => $this->t('Creating the unpublished draft'),
      'ready' => $this->t('Your unpublished changelog draft is ready'),
      'failed' => $this->t('No AI draft was created'),
      'cancelled' => $this->t('AI synthesis was cancelled'),
      default => $this->t('Status unavailable'),
    };
  }

  /**
   * Returns safe guidance for the current stage.
   */
  private function message(string $state, string $errorCode, array $health): string {
    if ($state === 'failed') {
      return (string) match ($errorCode) {
        'provider_unavailable' => $this->t('The selected provider is unavailable. Review AI configuration before trying again.'),
        'invalid_response' => $this->t('The provider response could not be safely used. Try a structured-output model or generate again.'),
        'provider_failure' => $this->t('The provider could not complete the request. Check its status before trying again.'),
        'stale_evidence' => $this->t('The release evidence changed after preview. Generate again from a fresh preview.'),
        'empty_response' => $this->t('The provider returned no usable release notes. Review the evidence or editorial profile.'),
        'provider_refusal' => $this->t('The provider declined this request. Adjust the evidence or instructions and try again.'),
        default => $this->t('The release and its evidence were not changed. Review the operation history for its support reference.'),
      };
    }
    return (string) match ($state) {
      'waiting' => $health['processor_state'] === 'scheduled'
        ? $this->t('The request is stored safely and will begin at the next configured processing opportunity.')
        : $this->t('The request is stored safely and is waiting for a synthesis worker. No provider request is running yet.'),
      'delayed' => $this->t('No provider request is running. Background processing needs attention before this job can continue.'),
      'analyzing' => $this->t('The configured provider is reviewing the eligible, privacy-filtered evidence.'),
      'consolidating' => $this->t('Changelogify is combining bounded batch results into one final changelog.'),
      'finalizing' => $this->t('The AI response is complete and current evidence is being revalidated before the draft is saved.'),
      'ready' => $this->t('Nothing was published automatically. Review and edit the draft before changing its editorial state.'),
      'cancelled' => $this->t('No draft was created and queued provider work will be ignored.'),
      default => $this->t('The current operation status could not be determined.'),
    };
  }

  /**
   * Returns an editorial processing-health label.
   */
  private function queueLabel(string $state): string {
    return (string) match ($state) {
      'active' => $this->t('Processor available'),
      'scheduled' => $this->t('Scheduled processing'),
      'delayed' => $this->t('Processor needs attention'),
      'idle' => $this->t('Queue is clear'),
      default => $this->t('No recent processor heartbeat'),
    };
  }

  /**
   * Returns a compact translated queue badge.
   */
  private function queueBadge(string $state): string {
    return (string) match ($state) {
      'active' => $this->t('Available'),
      'scheduled' => $this->t('Waiting'),
      'delayed' => $this->t('Delayed'),
      'idle' => $this->t('Clear'),
      default => $this->t('Unavailable'),
    };
  }

  /**
   * Explains what will cause a queued job to advance.
   */
  private function queueSummary(array $health): string {
    return (string) match ($health['processor_state']) {
      'active' => $this->t('A dedicated worker or recent Drupal cron run is available to claim queued synthesis steps.'),
      'scheduled' => $this->t('Drupal Automated Cron is enabled. Processing begins only when that schedule is due and Drupal receives a request.'),
      'delayed' => $this->t('The queue has waited at least 15 minutes without relevant worker activity.'),
      'idle' => $this->t('There are no synthesis steps waiting to be processed.'),
      default => $this->t('Configure a dedicated Changelogify worker or reliable external Drupal cron schedule.'),
    };
  }

  /**
   * Formats a safe operational timestamp.
   */
  private function timeLabel(int $timestamp): string {
    return $timestamp > 0
      ? $this->dateFormatter->format($timestamp, 'short')
      : (string) $this->t('Not recorded');
  }

  /**
   * Formats the next request-driven cron opportunity.
   */
  private function nextRunLabel(array $health): string {
    if ((int) $health['automated_cron_interval'] === 0) {
      return (string) $this->t('No Automated Cron schedule');
    }
    if ((int) $health['next_automated_cron'] <= 0) {
      return (string) $this->t('On the next eligible site request');
    }
    return $this->dateFormatter->format((int) $health['next_automated_cron'], 'short');
  }

}
