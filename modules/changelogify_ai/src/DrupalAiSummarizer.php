<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\InvalidResponseException;
use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizerInterface;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;

/**
 * Drupal AI adapter; no credential is accepted, stored, or logged here.
 */
final class DrupalAiSummarizer implements SummarizerInterface {

  public function __construct(private readonly object $providerManager, private readonly ConfigFactoryInterface $configFactory, private readonly PromptTemplateRegistry $prompts, private readonly ChatRequestFactoryInterface $chatRequests) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    if (!$this->configFactory->get('changelogify_ai.settings')->get('consent_external_processing')) {
      return FALSE;
    }
    try {
      [$providerId, $modelId] = $this->selection();
      if ($providerId === '' || $modelId === '' || !$this->providerManager->hasProvidersForOperationType('chat', TRUE)) {
        return FALSE;
      }
      $provider = $this->providerManager->createInstance($providerId);
      return $provider->isUsable('chat') && isset($provider->getConfiguredModels('chat')[$modelId]);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function selectedProviderModel(): ?array {
    try {
      [$providerId, $modelId] = $this->selection();
      return $providerId !== '' && $modelId !== '' ? ['provider' => $providerId, 'model' => $modelId] : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function summarize(SummarizationRequest $request): SummarizationResult {
    if (!$this->configFactory->get('changelogify_ai.settings')->get('consent_external_processing')) {
      throw new ProviderUnavailableException('External AI processing has not been approved.');
    }
    if (!$this->isAvailable()) {
      throw new ProviderUnavailableException('No configured Drupal AI chat provider is available.');
    }
    [$providerId, $modelId, $providerConfiguration] = $this->selection();
    try {
      $provider = $this->providerManager->createInstance($providerId);
      if ($providerConfiguration !== []) {
        $provider->setConfiguration($providerConfiguration);
      }
      $prompt = $this->prompts->build($request);
      $input = $this->chatRequests->create(
        $prompt['system'],
        $prompt['user'],
        $this->supportsStructuredOutput($provider, $modelId) ? $this->responseSchema() : NULL,
      );
      $output = $provider->chat($input, $modelId)->getNormalized()->getText();
    }
    catch (\Throwable $exception) {
      throw new TransientSummarizationException('The configured provider request failed.', 0, $exception);
    }
    try {
      $decoded = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
      $items = array_map(static fn(array $item): SummarizationItem => new SummarizationItem((string) $item['id'], (string) $item['section'], (string) $item['text'], array_values($item['source_ids'] ?? [])), $decoded['items'] ?? []);
      return new SummarizationResult($decoded['status'] ?? 'completed', $items, $decoded['omitted_source_ids'] ?? [], $decoded['warnings'] ?? [], $providerId, $modelId);
    }
    catch (\Throwable $exception) {
      throw new InvalidResponseException('The configured provider did not return a valid response.', 0, $exception);
    }
  }

  /**
   * Resolves the native Drupal AI provider-configuration value.
   *
   * @return array{string, string, array<string, mixed>}
   *   Provider ID, model ID, and provider-specific non-secret settings.
   */
  private function selection(): array {
    $config = $this->configFactory->get('changelogify_ai.settings')->get('provider') ?: [];
    $selection = !empty($config['use_default']) ? ($this->providerManager->getDefaultProviderForOperationType('chat') ?: []) : $config;
    return [
      (string) ($selection['provider_id'] ?? $selection['provider'] ?? ''),
      (string) ($selection['model_id'] ?? $selection['model'] ?? ''),
      empty($config['use_default']) && is_array($config['config'] ?? NULL) ? $config['config'] : [],
    ];
  }

  /**
   * Resolves Drupal AI's JSON-output capability without loading it early.
   */
  private function structuredOutputCapability(): mixed {
    $name = 'Drupal\\ai\\Enum\\AiModelCapability::ChatStructuredResponse';
    $capability = defined($name) ? constant($name) : 'chat_structured_response';
    return $capability;
  }

  /**
   * Determines whether the selected model can enforce native structured JSON.
   */
  private function supportsStructuredOutput(object $provider, string $modelId): bool {
    try {
      return $provider->modelSupportsCapabilities('chat', $modelId, [$this->structuredOutputCapability()]);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Returns the provider-neutral schema for a validated summary response.
   */
  private function responseSchema(): array {
    return [
      'name' => 'changelogify_summary',
      'description' => 'Evidence-backed Changelogify release-item suggestions.',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'required' => ['status', 'items', 'omitted_source_ids', 'warnings'],
        'properties' => [
          'status' => ['type' => 'string', 'enum' => ['completed', 'partial', 'refused']],
          'items' => [
            'type' => 'array',
            'maxItems' => 200,
            'items' => [
              'type' => 'object',
              'required' => ['id', 'section', 'text', 'source_ids'],
              'properties' => [
                'id' => ['type' => 'string'],
                'section' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
              ],
            ],
          ],
          'omitted_source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
          'warnings' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string']],
        ],
      ],
    ];
  }

}
