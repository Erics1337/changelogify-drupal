<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the versioned release-synthesis request and response contract.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisContractTest extends TestCase {

  /**
   * Supported stages and length presets are explicit request properties.
   */
  public function testBuildsVersionedSynthesisRequests(): void {
    $request = $this->request(
      SynthesisContract::STAGE_FINAL,
      SynthesisContract::PRESET_STANDARD,
    );
    self::assertSame(SynthesisContract::OPERATION, $request->operation);
    self::assertSame(SynthesisContract::VERSION, $request->synthesisVersion);
    self::assertSame(SynthesisContract::STAGE_FINAL, $request->synthesisStage);
    self::assertSame(SynthesisContract::PRESET_STANDARD, $request->lengthPreset);
  }

  /**
   * Final length presets provide explicit output bounds.
   */
  public function testReturnsPresetBoundsAndSchemas(): void {
    foreach ([
      SynthesisContract::PRESET_SHORT => 5,
      SynthesisContract::PRESET_STANDARD => 12,
      SynthesisContract::PRESET_DETAILED => 25,
    ] as $preset => $maximum) {
      $schema = SynthesisContract::responseSchema(
        SynthesisContract::VERSION,
        SynthesisContract::STAGE_FINAL,
        $preset,
      );
      self::assertSame('changelogify_synthesis_final_v2', $schema['name']);
      self::assertSame($maximum, $schema['schema']['properties']['items']['maxItems']);
      self::assertSame(
        ['added', 'changed', 'fixed', 'removed', 'security', 'other'],
        $schema['schema']['properties']['items']['items']['properties']['section']['enum'],
      );
    }
  }

  /**
   * Legacy operations remain valid without synthesis-only properties.
   */
  public function testLegacyRequestsRemainUnchanged(): void {
    $request = new SummarizationRequest(
      'humanize_release',
      'concise',
      ['change-1' => ['summary' => 'Evidence.']],
      '1',
      '1',
      'legacy-request',
    );
    self::assertNull($request->synthesisVersion);
    self::assertNull($request->synthesisStage);
    self::assertNull($request->lengthPreset);
  }

  /**
   * Unknown or inapplicable synthesis properties are rejected.
   *
   * @dataProvider invalidRequestProvider
   */
  #[DataProvider('invalidRequestProvider')]
  public function testRejectsInvalidSynthesisProperties(string $operation, ?string $version, ?string $stage, ?string $preset): void {
    $this->expectException(\InvalidArgumentException::class);
    new SummarizationRequest(
      $operation,
      'concise',
      ['change-1' => ['summary' => 'Evidence.']],
      '1',
      '1',
      'invalid-request',
      '',
      $version,
      $stage,
      $preset,
    );
  }

  /**
   * Provides invalid contract combinations.
   */
  public static function invalidRequestProvider(): array {
    return [
      'missing synthesis version' => [
        SynthesisContract::OPERATION, NULL, SynthesisContract::STAGE_FINAL, SynthesisContract::PRESET_SHORT,
      ],
      'unknown synthesis version' => [
        SynthesisContract::OPERATION, '999', SynthesisContract::STAGE_FINAL, SynthesisContract::PRESET_SHORT,
      ],
      'unknown stage' => [
        SynthesisContract::OPERATION, SynthesisContract::VERSION, 'unknown', SynthesisContract::PRESET_SHORT,
      ],
      'unknown preset' => [
        SynthesisContract::OPERATION, SynthesisContract::VERSION, SynthesisContract::STAGE_FINAL, 'unlimited',
      ],
      'synthesis properties on legacy operation' => [
        'complete_draft', SynthesisContract::VERSION,
        SynthesisContract::STAGE_FINAL, SynthesisContract::PRESET_SHORT,
      ],
    ];
  }

  /**
   * Creates a valid synthesis request.
   */
  private function request(string $stage, string $preset): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      'concise',
      ['change-1' => ['summary' => 'Evidence.']],
      '1',
      '1',
      'synthesis-request',
      '',
      SynthesisContract::VERSION,
      $stage,
      $preset,
    );
  }

}
