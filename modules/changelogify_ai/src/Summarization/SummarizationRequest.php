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
   */
  public function __construct(
    public readonly string $operation,
    public readonly string $profile,
    public readonly array $evidence,
    public readonly string $promptVersion,
    public readonly string $policyVersion,
    public readonly string $idempotencyKey,
    public readonly string $instructions = '',
  ) {}

}
