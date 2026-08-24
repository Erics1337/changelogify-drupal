<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Normalized provider result, before evidence validation.
 */
final class SummarizationResult {

  /**
   * Creates a normalized but unvalidated provider result.
   *
   * @param string $status
   *   Completion, partial, or refusal state.
   * @param SummarizationItem[] $items
   *   Proposed items.
   * @param string[] $omittedSourceIds
   *   Selected evidence deliberately omitted.
   * @param string[] $warnings
   *   Provider or validation warnings.
   * @param string|null $providerId
   *   Provider identifier, when available.
   * @param string|null $modelId
   *   Model identifier, when available.
   * @param int|null $inputTokens
   *   Input-token count, when supplied.
   * @param int|null $outputTokens
   *   Output-token count, when supplied.
   * @param string|null $operationId
   *   Correlation ID assigned by the operation manager.
   */
  public function __construct(
    public readonly string $status,
    public readonly array $items = [],
    public readonly array $omittedSourceIds = [],
    public readonly array $warnings = [],
    public readonly ?string $providerId = NULL,
    public readonly ?string $modelId = NULL,
    public readonly ?int $inputTokens = NULL,
    public readonly ?int $outputTokens = NULL,
    public readonly ?string $operationId = NULL,
  ) {}

}
