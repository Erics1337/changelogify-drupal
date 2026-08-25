<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify_ai\Summarization\InvalidResponseException;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Maps internal AI failures to safe, actionable editor guidance.
 */
final class AiFailureMessage {

  use StringTranslationTrait;

  /**
   * Returns a safe failure category, message, and appropriate next action.
   *
   * @return array{code: string, message: \Drupal\Core\StringTranslation\TranslatableMarkup, configure: bool, retry: bool}
   *   Editor-facing failure details.
   */
  public function describe(\Throwable $exception): array {
    $code = $this->code($exception);
    $message = match ($code) {
      'provider_unavailable' => $this->t('AI drafting is not ready. Review processing consent and select an available Drupal AI provider and model.'),
      'invalid_response' => $this->t('The AI provider responded, but its result could not be safely used. Try again or choose a model that supports structured output.'),
      'provider_failure' => $this->t('The AI provider could not complete the request. Check the provider status and try again.'),
      'stale_evidence' => $this->t('The selected evidence changed after preview. Preview the release window again before retrying.'),
      'empty_response' => $this->t('The provider returned no usable release items. Review the selected evidence or try a different editorial profile.'),
      'provider_refusal' => $this->t('The provider did not complete this draft. Adjust the selection or instructions and try again.'),
      default => $this->t('No AI draft was created. The release and its evidence were not changed. Try again or use the support reference below when reviewing logs.'),
    };
    return $this->result(
      $code,
      $message,
      in_array($code, ['provider_unavailable', 'invalid_response'], TRUE),
      in_array($code, [
        'invalid_response',
        'provider_failure',
        'empty_response',
        'provider_refusal',
        'generation_failure',
      ], TRUE),
    );
  }

  /**
   * Returns a stable failure code without requiring rendering services.
   */
  public function code(\Throwable $exception): string {
    if ($exception instanceof ProviderUnavailableException) {
      return 'provider_unavailable';
    }
    if ($exception instanceof InvalidResponseException) {
      return 'invalid_response';
    }
    if ($exception instanceof TransientSummarizationException) {
      return 'provider_failure';
    }
    if ($exception instanceof \UnexpectedValueException) {
      $message = $exception->getMessage();
      if (str_contains($message, 'stale') || str_contains($message, 'no longer available')) {
        return 'stale_evidence';
      }
      if (str_contains($message, 'did not return any release items')) {
        return 'empty_response';
      }
      if (str_contains($message, 'did not complete')) {
        return 'provider_refusal';
      }
    }
    return 'generation_failure';
  }

  /**
   * Builds one categorized failure result.
   */
  private function result(string $code, TranslatableMarkup $message, bool $configure = FALSE, bool $retry = FALSE): array {
    return [
      'code' => $code,
      'message' => $message,
      'configure' => $configure,
      'retry' => $retry,
    ];
  }

}
