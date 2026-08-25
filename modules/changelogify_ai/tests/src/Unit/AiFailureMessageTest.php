<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\AiFailureMessage;
use Drupal\changelogify_ai\Summarization\InvalidResponseException;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\TransientSummarizationException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests safe AI failure categories and next actions.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class AiFailureMessageTest extends UnitTestCase {

  /**
   * Tests editor-facing mappings never expose provider exception messages.
   */
  #[DataProvider('failureProvider')]
  public function testFailureMapping(\Throwable $exception, string $code, bool $configure, bool $retry): void {
    $messages = new AiFailureMessage();
    $messages->setStringTranslation($this->getStringTranslationStub());
    $result = $messages->describe($exception);

    self::assertSame($code, $result['code']);
    self::assertSame($configure, $result['configure']);
    self::assertSame($retry, $result['retry']);
    self::assertStringNotContainsString('sensitive-provider-detail', (string) $result['message']);
  }

  /**
   * Provides representative safe failure categories.
   */
  public static function failureProvider(): array {
    return [
      'provider unavailable' => [
        new ProviderUnavailableException('sensitive-provider-detail'),
        'provider_unavailable', TRUE, FALSE,
      ],
      'invalid response' => [new InvalidResponseException('sensitive-provider-detail'), 'invalid_response', TRUE, TRUE],
      'provider failure' => [
        new TransientSummarizationException('sensitive-provider-detail'),
        'provider_failure', FALSE, TRUE,
      ],
      'stale evidence' => [
        new \UnexpectedValueException('Evidence is stale and no longer available.'),
        'stale_evidence', FALSE, FALSE,
      ],
      'provider refusal' => [
        new \UnexpectedValueException('The provider did not complete the draft.'),
        'provider_refusal', FALSE, TRUE,
      ],
      'empty response' => [
        new \UnexpectedValueException('The provider did not return any release items.'),
        'empty_response', FALSE, TRUE,
      ],
      'unknown failure' => [new \RuntimeException('sensitive-provider-detail'), 'generation_failure', FALSE, TRUE],
    ];
  }

}
