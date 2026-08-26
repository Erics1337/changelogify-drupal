<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\changelogify_ai\AiOperationManager;
use Drupal\changelogify_ai\AiReadinessChecker;
use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests actionable AI readiness categories.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class AiReadinessCheckerTest extends TestCase {

  /**
   * Tests stable prerequisite categories.
   */
  #[DataProvider('statusProvider')]
  public function testStatus(bool $consent, array $provider, ?array $selection, bool $available, string $code): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
      'consent_external_processing' => $consent,
      'provider' => $provider,
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    $checker = new AiReadinessChecker(
      $factory,
      $this->operations($selection, $available),
    );

    $status = $checker->status();
    self::assertSame($code, $status['code']);
    self::assertSame($code === 'ready', $status['ready']);
  }

  /**
   * Provides readiness configurations.
   */
  public static function statusProvider(): array {
    return [
      'consent missing' => [FALSE, [], NULL, FALSE, 'consent_missing'],
      'provider missing' => [TRUE, ['use_default' => FALSE], NULL, FALSE, 'provider_missing'],
      'model missing' => [TRUE, ['use_default' => FALSE, 'provider' => 'example'], NULL, FALSE, 'model_missing'],
      'provider unavailable' => [TRUE, [], ['provider' => 'example', 'model' => 'model'], FALSE, 'provider_unavailable'],
      'ready' => [TRUE, [], ['provider' => 'example', 'model' => 'model'], TRUE, 'ready'],
    ];
  }

  /**
   * Creates an operation manager with controlled readiness behavior.
   */
  private function operations(?array $selection, bool $available): AiOperationManager {
    $summarizer = new class($selection, $available) implements SummarizerInterface {

      public function __construct(
        private readonly ?array $selection,
        private readonly bool $available,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function isAvailable(): bool {
        return $this->available;
      }

      /**
       * {@inheritdoc}
       */
      public function selectedProviderModel(): ?array {
        return $this->selection;
      }

      /**
       * {@inheritdoc}
       */
      public function summarize(SummarizationRequest $request): SummarizationResult {
        throw new \LogicException('Not used by readiness tests.');
      }

    };
    return new AiOperationManager(
      $summarizer,
      new ResultValidator(),
      $this->createMock(KeyValueFactoryInterface::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

}
