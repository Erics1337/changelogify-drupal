<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\Summarization\FakeSummarizer;
use Drupal\changelogify_ai\PromptTemplateRegistry;
use Drupal\changelogify_ai\Summarization\ProviderUnavailableException;
use Drupal\changelogify_ai\Summarization\SummarizationException;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
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
   * Auto deterministically groups several evidence records into fewer notes.
   */
  public function testAutoGroupsEvidence(): void {
    $evidence = [];
    for ($index = 1; $index <= 12; $index++) {
      $evidence["change-{$index}"] = [
        'section' => 'changed',
        'summary' => "Safe evidence {$index}.",
      ];
    }
    $request = new SummarizationRequest(
      SynthesisContract::OPERATION,
      'concise',
      $evidence,
      '2',
      '1',
      'auto-test-key',
      '',
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_AUTO,
    );

    $result = (new FakeSummarizer())->summarize($request);

    self::assertLessThan(count($evidence), count($result->items));
    self::assertSame(
      array_keys($evidence),
      array_merge(...array_map(static fn ($item): array => $item->sourceIds, $result->items)),
    );
  }

  /**
   * The nine-event acceptance fixture produces a compact grouped result.
   */
  public function testAutoGroupsNineEventAcceptanceFixture(): void {
    $evidence = [];
    foreach ([
      'block-updated',
      'test-provider-uninstalled',
      'openrouter-installed',
      'language-installed',
      'translation-installed',
      'first-block-created',
      'first-block-updated',
      'second-block-created',
      'second-block-updated',
    ] as $id) {
      $evidence[$id] = [
        'section' => 'changed',
        'summary' => "Recorded change {$id}.",
      ];
    }
    $request = new SummarizationRequest(
      SynthesisContract::OPERATION,
      'public_product',
      $evidence,
      PromptTemplateRegistry::VERSION,
      '1',
      'nine-event-acceptance',
      '',
      SynthesisContract::VERSION,
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_AUTO,
    );

    $result = (new FakeSummarizer())->summarize($request);

    self::assertCount(3, $result->items);
    self::assertSame(
      array_keys($evidence),
      array_merge(...array_map(static fn ($item): array => $item->sourceIds, $result->items)),
    );
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
