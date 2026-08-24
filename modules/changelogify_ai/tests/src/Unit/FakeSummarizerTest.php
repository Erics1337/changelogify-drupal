<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\SummarizationException;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests deterministic no-network provider scenarios.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class FakeSummarizerTest extends TestCase {

  /**
   * Tests a fake successful response preserves selected evidence identity.
   */
  public function testSuccess(): void {
    $result = (new FakeSummarizer())->summarize($this->request());
    self::assertSame('completed', $result->status);
    self::assertSame(['change-1'], $result->items[0]->sourceIds);
  }

  /**
   * Tests refusal remains an explicit non-mutating state.
   */
  public function testRefusal(): void {
    self::assertSame('refused', (new FakeSummarizer('refusal'))->summarize($this->request())->status);
  }

  /**
   * Tests malformed output is returned for independent validator rejection.
   */
  public function testMalformedOutput(): void {
    $result = (new FakeSummarizer('malformed'))->summarize($this->request());
    self::assertStringContainsString('<script>', $result->items[0]->text);
  }

  /**
   * Tests transient and timeout cases are predictable failures.
   *
   * @dataProvider failureModeProvider
   */
  #[DataProvider('failureModeProvider')]
  public function testFailureModes(string $mode): void {
    $this->expectException(SummarizationException::class);
    (new FakeSummarizer($mode))->summarize($this->request());
  }

  /**
   * Tests missing capability is distinguishable from a transient error.
   */
  public function testMissingCapability(): void {
    $this->expectException(ProviderUnavailableException::class);
    (new FakeSummarizer('missing_capability'))->summarize($this->request());
  }

  /**
   * Provides deterministic transient failure modes.
   */
  public static function failureModeProvider(): array {
    return [['failure'], ['timeout']];
  }

  /**
   * Creates a minimum safe provider request.
   */
  private function request(): SummarizationRequest {
    return new SummarizationRequest(
      'complete_draft',
      'concise',
      ['change-1' => ['section' => 'changed', 'summary' => 'Safe evidence.']],
      '1',
      '1',
      'test-key',
    );
  }

}
