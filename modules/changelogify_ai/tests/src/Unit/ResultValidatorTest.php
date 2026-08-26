<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\ResultValidator;
use Drupal\changelogify_ai\Summarization\SummarizationItem;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SynthesisContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests provider-independent output validation.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class ResultValidatorTest extends TestCase {

  /**
   * Tests valid evidence-backed output is accepted.
   */
  public function testAcceptsSelectedEvidence(): void {
    $result = new SummarizationResult('completed', [
      new SummarizationItem('item-1', 'fixed', 'Corrected the selected behavior.', ['change-1']),
    ]);
    (new ResultValidator())->validate($result, ['change-1']);
    self::addToAssertionCount(1);
  }

  /**
   * Refusal and partial output remain explicit, non-success result states.
   */
  public function testAcceptsExplicitRefusalAndPartialStates(): void {
    $validator = new ResultValidator();
    $validator->validate(new SummarizationResult('refused'), ['change-1']);
    $validator->validate(new SummarizationResult('partial', [
      new SummarizationItem('item-1', 'changed', 'Only available item.', ['change-1']),
    ], ['change-2'], ['One source was omitted.']), ['change-1', 'change-2']);
    self::addToAssertionCount(2);
  }

  /**
   * Tests unsafe or unsupported results cannot be applied.
   *
   * @dataProvider invalidResultProvider
   */
  #[DataProvider('invalidResultProvider')]
  public function testRejectsUnsafeOutput(SummarizationResult $result): void {
    $this->expectException(\UnexpectedValueException::class);
    (new ResultValidator())->validate($result, ['change-1']);
  }

  /**
   * Tests aggregate response size is bounded independently of each item.
   */
  public function testRejectsOversizedResponse(): void {
    $this->expectException(\LengthException::class);
    $result = new SummarizationResult('completed', [
      new SummarizationItem('item-1', 'fixed', 'Text.', ['change-1']),
    ], [], array_fill(0, 40, str_repeat('w', 1700)));
    (new ResultValidator())->validate($result, ['change-1']);
  }

  /**
   * Final synthesis output cannot exceed its selected length preset.
   */
  public function testEnforcesFinalSynthesisLengthPreset(): void {
    $items = [];
    for ($index = 1; $index <= 6; $index++) {
      $items[] = new SummarizationItem("item-{$index}", 'changed', "Change {$index}.", ['change-1']);
    }
    $this->expectException(\LengthException::class);
    (new ResultValidator())->validate(
      new SummarizationResult('completed', $items),
      ['change-1'],
      $this->synthesisRequest(SynthesisContract::STAGE_FINAL, SynthesisContract::PRESET_SHORT),
    );
  }

  /**
   * Provides hostile and invalid provider-result fixtures.
   */
  public static function invalidResultProvider(): array {
    return [
      'unknown evidence' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'fixed', 'Text.', ['unknown']),
      ],
        ),
      ],
      'markup' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'fixed', '<script>alert(1)</script>', ['change-1']),
      ],
        ),
      ],
      'duplicate ID' => [new SummarizationResult('completed', [
        new SummarizationItem('same', 'fixed', 'One.', ['change-1']),
        new SummarizationItem('same', 'fixed', 'Two.', ['change-1']),
      ],
        ),
      ],
      'unknown section' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'invented', 'Text.', ['change-1']),
      ],
        ),
      ],
      'missing evidence' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'fixed', 'Text.', []),
      ],
        ),
      ],
      'unknown omitted evidence' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'fixed', 'Text.', ['change-1']),
      ], ['hostile-unknown'],
        ),
      ],
      'oversized item text' => [new SummarizationResult('completed', [
        new SummarizationItem('item-1', 'fixed', str_repeat('x', 2049), ['change-1']),
      ],
        ),
      ],
      'unknown state' => [new SummarizationResult('unknown', [
        new SummarizationItem('item-1', 'fixed', 'Text.', ['change-1']),
      ],
        ),
      ],
    ];
  }

  /**
   * Creates one valid versioned synthesis request.
   */
  private function synthesisRequest(string $stage, string $preset): SummarizationRequest {
    return new SummarizationRequest(
      SynthesisContract::OPERATION,
      'concise',
      ['change-1' => ['summary' => 'Evidence.']],
      '1',
      '1',
      "{$stage}-{$preset}",
      '',
      SynthesisContract::VERSION,
      $stage,
      $preset,
    );
  }

}
