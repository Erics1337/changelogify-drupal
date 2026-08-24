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
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
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
      public bool $structuredOutput = TRUE;

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

      public function chat(object $input, string $model): object {
        $this->input = $input;
        $this->model = $model;
        return new class {

          public function getNormalized(): object {
            return new class {

              public function getText(): string {
                return '{"status":"completed","items":[{"id":"change-1","section":"changed","text":"Clear change.","source_ids":["change-1"]}]}';
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
    self::assertSame('completed', $result->status);
    self::assertSame('change-1', $result->items[0]->id);
    self::assertStringContainsString('Return JSON only.', $provider->input->system);
    self::assertSame('changelogify_summary', $requests->schema['name']);
    $provider->structuredOutput = FALSE;
    $summarizer->summarize($this->request());
    self::assertNull($requests->schema);
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

}
