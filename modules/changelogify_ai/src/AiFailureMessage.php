<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify_ai\Summarization\InvalidResponseException;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;

/**
 * Maps internal AI failures to safe, actionable editor guidance.
 */
final class AiFailureMessage {

  /**
   * Returns a safe failure category, message, and appropriate next action.
   *
   * @return array{code: string, message: string, configure: bool, retry: bool}
   *   Editor-facing failure details.
   */
  public function describe(\Throwable $exception): array {
    if ($exception instanceof ProviderUnavailableException) {
      return $this->result(
        'provider_unavailable',
        'AI drafting is not ready. Review processing consent and select an available Drupal AI provider and model.',
        TRUE,
      );
    }
    if ($exception instanceof InvalidResponseException) {
      return $this->result(
        'invalid_response',
        'The AI provider responded, but its result could not be safely used. Try again or choose a model that supports structured output.',
        TRUE,
        TRUE,
      );
    }
    if ($exception instanceof TransientSummarizationException) {
      return $this->result(
        'provider_failure',
        'The AI provider could not complete the request. Check the provider status and try again.',
        FALSE,
        TRUE,
      );
    }
    if ($exception instanceof \UnexpectedValueException) {
      $message = $exception->getMessage();
      if (str_contains($message, 'stale') || str_contains($message, 'no longer available')) {
        return $this->result(
          'stale_evidence',
          'The selected evidence changed after preview. Preview the release window again before retrying.',
        );
      }
      if (str_contains($message, 'did not return any release items')) {
        return $this->result(
          'empty_response',
          'The provider returned no usable release items. Review the selected evidence or try a different editorial profile.',
          FALSE,
          TRUE,
        );
      }
      if (str_contains($message, 'did not complete')) {
        return $this->result(
          'provider_refusal',
          'The provider did not complete this draft. Adjust the selection or instructions and try again.',
          FALSE,
          TRUE,
        );
      }
    }
    return $this->result(
      'generation_failure',
      'No AI draft was created. The release and its evidence were not changed. Try again or use the support reference below when reviewing logs.',
      FALSE,
      TRUE,
    );
  }

  /**
   * Builds one categorized failure result.
   */
  private function result(string $code, string $message, bool $configure = FALSE, bool $retry = FALSE): array {
    return [
      'code' => $code,
      'message' => $message,
      'configure' => $configure,
      'retry' => $retry,
    ];
  }

}
