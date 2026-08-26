<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Immutable, policy-filtered request sent to a summarizer.
 */
final class SummarizationRequest {

  /**
   * Creates a policy-filtered generation request.
   *
   * @param string $operation
   *   Requested operation type.
   * @param string $profile
   *   Selected editorial profile.
   * @param array<string, array<string, mixed>> $evidence
   *   Redacted evidence keyed by stable change-set ID.
   * @param string $promptVersion
   *   Built-in prompt version.
   * @param string $policyVersion
   *   Payload-policy version.
   * @param string $idempotencyKey
   *   Stable duplicate-prevention key.
   * @param string $instructions
   *   Temporary editor instructions for this request only.
   * @param string|null $synthesisVersion
   *   Versioned synthesis contract, only for release synthesis.
   * @param string|null $synthesisStage
   *   Intermediate or final synthesis stage.
   * @param string|null $lengthPreset
   *   Short, standard, or detailed final-output bound.
   */
  public function __construct(
    public readonly string $operation,
    public readonly string $profile,
    public readonly array $evidence,
    public readonly string $promptVersion,
    public readonly string $policyVersion,
    public readonly string $idempotencyKey,
    public readonly string $instructions = '',
    public readonly ?string $synthesisVersion = NULL,
    public readonly ?string $synthesisStage = NULL,
    public readonly ?string $lengthPreset = NULL,
  ) {
    SynthesisContract::validateRequest(
      $this->operation,
      $this->synthesisVersion,
      $this->synthesisStage,
      $this->lengthPreset,
    );
  }

  /**
   * Returns the synthesis version, including for pre-contract queued objects.
   */
  public function getSynthesisVersion(): ?string {
    return $this->synthesisVersion ?? NULL;
  }

  /**
   * Returns the synthesis stage, including for pre-contract queued objects.
   */
  public function getSynthesisStage(): ?string {
    return $this->synthesisStage ?? NULL;
  }

  /**
   * Returns the length preset, including for pre-contract queued objects.
   */
  public function getLengthPreset(): ?string {
    return $this->lengthPreset ?? NULL;
  }

}
