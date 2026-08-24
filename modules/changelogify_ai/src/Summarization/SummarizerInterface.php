<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Provider-independent contract for a bounded AI operation. */
interface SummarizerInterface {

  /**
   * Reports whether an approved, capable provider is available right now.
   */
  public function isAvailable(): bool;

  /**
   * Returns the selected provider and model identifiers, never credentials.
   *
   * @return array{provider: string, model: string}|null
   *   The selected identifiers, or NULL when no selection is available.
   */
  public function selectedProviderModel(): ?array;

  /**
   * Generates an untrusted, structured summary result. */
  public function summarize(SummarizationRequest $request): SummarizationResult;

}
