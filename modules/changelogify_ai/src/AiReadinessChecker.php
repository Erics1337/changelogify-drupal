<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reports actionable AI drafting prerequisites without exposing credentials.
 */
final class AiReadinessChecker {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiOperationManager $operations,
  ) {}

  /**
   * Returns the current readiness category and safe editor-facing message.
   *
   * @return array{ready: bool, code: string, message: string}
   *   Stable readiness data.
   */
  public function status(): array {
    $config = $this->configFactory->get('changelogify_ai.settings');
    if (!$config->get('consent_external_processing')) {
      return $this->result('consent_missing', 'AI drafting is off because permission to process selected release evidence has not been granted.');
    }
    $provider = $config->get('provider') ?: [];
    $selection = $this->operations->selectedProviderModel();
    if ($selection === NULL) {
      if (empty($provider['use_default']) && empty($provider['provider'])) {
        return $this->result('provider_missing', 'AI drafting needs a Drupal AI chat provider.');
      }
      return $this->result('model_missing', 'AI drafting needs a chat model for the selected Drupal AI provider.');
    }
    if (!$this->operations->isAvailable()) {
      return $this->result('provider_unavailable', 'The selected AI provider or model is configured but is not currently available.');
    }
    return [
      'ready' => TRUE,
      'code' => 'ready',
      'message' => 'AI drafting is ready.',
    ];
  }

  /**
   * Builds one not-ready result.
   */
  private function result(string $code, string $message): array {
    return ['ready' => FALSE, 'code' => $code, 'message' => $message];
  }

}
