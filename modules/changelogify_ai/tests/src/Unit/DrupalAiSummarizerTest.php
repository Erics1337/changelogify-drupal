<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

// phpcs:disable Drupal.Commenting, Drupal.Classes.ClassCreateInstance

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\changelogify_ai\ChatRequestFactoryInterface;
use Drupal\changelogify_ai\DrupalAiSummarizer;
use Drupal\changelogify_ai\PromptTemplateRegistry;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\InvalidResponseException;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Drupal AI boundary without credentials or an external request.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class DrupalAiSummarizerTest extends TestCase {

  /**
   * Explicit native provider configuration reaches the selected model.
   */
  public function testExplicitProviderConfigurationExecutes(): void {
    $provider = new class() {
      public array $configuration = [];
      public ?object $input = NULL;
      public ?string $model = NULL;
      public ?string $systemRole = NULL;
      public bool $structuredOutput = TRUE;
      public string $responseText = '{"status":"completed","items":[{"id":"change-1","section":"changed","text":"Clear change.","source_ids":["change-1"]}]}';

      public function isUsable(string $operation, array $capabilities = []): bool {
        return $operation === 'chat' && $capabilities === [] && $this->configuration === ['temperature' => 0];
      }

      public function modelSupportsCapabilities(string $operation, string $model, array $capabilities): bool {
        $capability = $capabilities[0] ?? NULL;
        $value = $capability instanceof \BackedEnum ? $capability->value : $capability;
        return $this->structuredOutput && $operation === 'chat' && $model === 'model-a' && $value === 'chat_structured_response';
      }

      public function getConfiguredModels(string $operation): array {
        return $operation === 'chat' ? ['model-a' => 'Model A'] : [];
      }

      public function setConfiguration(array $configuration): void {
        $this->configuration = $configuration;
      }

      public function setChatSystemRole(string $systemRole): void {
        $this->systemRole = $systemRole;
      }

      public function chat(object $input, string $model): object {
        $this->input = $input;
        $this->model = $model;
        return new class($this->responseText) {

          public function __construct(private readonly string $responseText) {}

          public function getNormalized(): object {
            return new class($this->responseText) {

              public function __construct(private readonly string $responseText) {}

              public function getText(): string {
                return $this->responseText;
              }

            };
          }

        };
      }

    };
    $manager = new class($provider) {
      public ?string $requestedProvider = NULL;

      public function __construct(private object $provider) {}

      public function hasProvidersForOperationType(string $operation, bool $setup): bool {
        return $operation === 'chat' && $setup;
      }

      public function createInstance(string $providerId): object {
        $this->requestedProvider = $providerId;
        return $this->provider;
      }

      public function getDefaultProviderForOperationType(string $operation): mixed {
        return NULL;
      }

    };
    $requests = new class implements ChatRequestFactoryInterface {
      public ?array $schema = NULL;

      public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object {
        $this->schema = $structuredSchema;
        return new class($systemPrompt, $userPrompt) {

          public function __construct(public string $system, public string $user) {}

        };
      }

    };
    $summarizer = $this->summarizer($manager, [
      'use_default' => FALSE,
      'provider' => 'test_provider',
      'model' => 'model-a',
      'config' => ['temperature' => 0],
    ], $requests);
    self::assertTrue($summarizer->isAvailable());
    self::assertSame(['provider' => 'test_provider', 'model' => 'model-a'], $summarizer->selectedProviderModel());
    $result = $summarizer->summarize($this->request());
    self::assertSame('test_provider', $manager->requestedProvider);
    self::assertSame('model-a', $provider->model);
    self::assertSame(['temperature' => 0], $provider->configuration);
    self::assertStringContainsString('Return JSON only.', (string) $provider->systemRole);
    self::assertSame('completed', $result->status);
    self::assertSame('change-1', $result->items[0]->id);
    self::assertStringContainsString('Return JSON only.', $provider->input->system);
    self::assertSame('changelogify_summary', $requests->schema['name']);
    self::assertFalse($requests->schema['schema']['additionalProperties']);
    self::assertFalse($requests->schema['schema']['properties']['items']['items']['additionalProperties']);
    $summarizer->summarize($this->synthesisRequest());
    self::assertSame('changelogify_synthesis_final_v2', $requests->schema['name']);
    self::assertSame(5, $requests->schema['schema']['properties']['items']['maxItems']);
    self::assertSame(
      ['added', 'changed', 'fixed', 'removed', 'security', 'other'],
      $requests->schema['schema']['properties']['items']['items']['properties']['section']['enum'],
    );
    $provider->structuredOutput = FALSE;
    $summarizer->summarize($this->request());
    self::assertNull($requests->schema);
    $provider->responseText = '{"status":"completed","items":[{"id":"safe","section":"added","text":"Created the News block.","source_ids":["public-change"]},{"id":"mixed","section":"added","text":"Installed modules.","source_ids":["public-change","internal-provider"]},{"id":"internal","section":"added","text":"Installed a provider.","source_ids":["internal-provider"]}],"omitted_source_ids":[]}';
    $filtered = $summarizer->summarize($this->publicProductSynthesisRequest());
    self::assertSame(['safe'], array_map(static fn ($item): string => $item->id, $filtered->items));
    self::assertSame(['internal-provider'], $filtered->omittedSourceIds);
  }

  /**
   * Imported configuration without a usable provider fails safely.
   */
  public function testUnavailableProviderIsNotReady(): void {
    $manager = new class {

      public function hasProvidersForOperationType(string $operation, bool $setup): bool {
        return FALSE;
      }

      public function getDefaultProviderForOperationType(string $operation): mixed {
        return NULL;
      }

    };
    $requests = new class implements ChatRequestFactoryInterface {

      public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object {
        throw new \LogicException('Unavailable configuration must not build a request.');
      }

    };
    self::assertFalse($this->summarizer($manager, [
      'use_default' => FALSE,
      'provider' => 'not_installed',
      'model' => 'missing',
      'config' => [],
    ], $requests)->isAvailable());
  }

  /**
   * Default-provider selection never reuses stale explicit configuration.
   */
  public function testDefaultProviderDoesNotReceiveExplicitConfiguration(): void {
    $provider = new class() {
      public array $configuration = ['unchanged'];

      public function isUsable(string $operation, array $capabilities = []): bool {
        return TRUE;
      }

      public function getConfiguredModels(string $operation): array {
        return ['default-model' => 'Default'];
      }

      public function setConfiguration(array $configuration): void {
        $this->configuration = $configuration;
      }

    };
    $manager = new class($provider) {

      public function __construct(private object $provider) {}

      public function hasProvidersForOperationType(string $operation, bool $setup): bool {
        return TRUE;
      }

      public function getDefaultProviderForOperationType(string $operation): array {
        return ['provider_id' => 'default-provider', 'model_id' => 'default-model'];
      }

      public function createInstance(string $providerId): object {
        return $this->provider;
      }

    };
    $requests = $this->createMock(ChatRequestFactoryInterface::class);
    $summarizer = $this->summarizer($manager, [
      'use_default' => TRUE,
      'config' => ['temperature' => 2],
    ], $requests);
    $selection = new \ReflectionMethod($summarizer, 'selection');
    self::assertSame(['default-provider', 'default-model', []], $selection->invoke($summarizer));
  }

  /**
   * Consent is checked before the provider manager can be contacted.
   */
  public function testConsentBlocksProviderAccess(): void {
    $manager = new class {

      public function hasProvidersForOperationType(string $operation, bool $setup): bool {
        throw new \LogicException('Provider discovery must not happen without consent.');
      }

    };
    $requests = new class implements ChatRequestFactoryInterface {

      public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object {
        throw new \LogicException('A request must not be built without consent.');
      }

    };
    $summarizer = $this->summarizer($manager, [], $requests, FALSE);
    self::assertFalse($summarizer->isAvailable());
    $this->expectException(ProviderUnavailableException::class);
    $summarizer->summarize($this->request());
  }

  /**
   * Strict JSON fallback rejects malformed text without provider credentials.
   */
  public function testStrictJsonFallbackRejectsMalformedProviderText(): void {
    $provider = new class() {

      public function isUsable(string $operation, array $capabilities = []): bool {
        return TRUE;
      }

      public function getConfiguredModels(string $operation): array {
        return ['fallback-model' => 'Fallback model'];
      }

      public function modelSupportsCapabilities(string $operation, string $model, array $capabilities): bool {
        return FALSE;
      }

      public function setConfiguration(array $configuration): void {}

      public function chat(object $input, string $model): object {
        return new class {

          public function getNormalized(): object {
            return new class {

              public function getText(): string {
                return 'Here is your changelog instead of strict JSON.';
              }

            };
          }

        };
      }

    };
    $manager = new class($provider) {

      public function __construct(private object $provider) {}

      public function hasProvidersForOperationType(string $operation, bool $setup): bool {
        return TRUE;
      }

      public function createInstance(string $providerId): object {
        return $this->provider;
      }

      public function getDefaultProviderForOperationType(string $operation): mixed {
        return NULL;
      }

    };
    $requests = new class implements ChatRequestFactoryInterface {

      public ?array $schema = ['unexpected'];

      public function create(string $systemPrompt, string $userPrompt, ?array $structuredSchema = NULL): object {
        $this->schema = $structuredSchema;
        return new \stdClass();
      }

    };
    $summarizer = $this->summarizer($manager, [
      'use_default' => FALSE,
      'provider' => 'local-test-provider',
      'model' => 'fallback-model',
      'config' => [],
    ], $requests);
    try {
      $summarizer->summarize($this->synthesisRequest());
      self::fail('Malformed fallback text was accepted.');
    }
    catch (InvalidResponseException) {
      self::assertNull($requests->schema);
    }
  }

  /**
   * Builds a configured adapter with no API key in Changelogify configuration.
   */
  private function summarizer(object $providerManager, array $provider, ChatRequestFactoryInterface $requests, bool $consent = TRUE): DrupalAiSummarizer {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($provider, $consent): mixed {
      return match ($key) {
        'consent_external_processing' => $consent,
        'provider' => $provider,
        'organization_guidance' => '',
        'output_language' => 'en',
        default => NULL,
      };
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    return new DrupalAiSummarizer($providerManager, $configFactory, new PromptTemplateRegistry($configFactory), $requests);
  }

  /**
   * Returns one bounded evidence request.
   */
  private function request(): SummarizationRequest {
    return new SummarizationRequest('complete_draft', 'concise', [
      'change-1' => ['id' => 'change-1', 'section' => 'changed', 'summary' => 'Evidence.'],
    ], '1', '1', 'operation-1');
  }

  /**
   * Returns one versioned final-synthesis request.
   */
  private function synthesisRequest(): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      'concise',
      ['change-1' => ['id' => 'change-1', 'section' => 'changed', 'summary' => 'Evidence.']],
      '1',
      '1',
      'synthesis-operation-1',
      '',
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_SHORT,
    );
  }

  /**
   * Returns a synthesis request with mandatory Public product omissions.
   */
  private function publicProductSynthesisRequest(): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      'public_product',
      [
        'public-change' => ['summary' => 'Created Basic block: News'],
        'internal-provider' => [
          'summary' => 'Installed module: ai_provider_openrouter',
          'event_types' => ['module_installed'],
        ],
      ],
      PromptTemplateRegistry::VERSION,
      '1',
      'public-product-policy',
      '',
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_AUTO,
    );
  }

}
