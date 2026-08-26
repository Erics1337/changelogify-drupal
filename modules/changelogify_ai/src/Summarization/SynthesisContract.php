<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Defines the versioned provider-neutral release-synthesis contract.
 */
final class SynthesisContract {

  public const OPERATION = 'synthesize_release';
  public const VERSION = '1';
  public const STAGE_INTERMEDIATE = 'intermediate';
  public const STAGE_FINAL = 'final';
  public const PRESET_SHORT = 'short';
  public const PRESET_STANDARD = 'standard';
  public const PRESET_DETAILED = 'detailed';

  private const INTERMEDIATE_MAX_ITEMS = 50;
  private const FINAL_MAX_ITEMS = [
    self::PRESET_SHORT => 5,
    self::PRESET_STANDARD => 12,
    self::PRESET_DETAILED => 25,
  ];

  /**
   * Validates synthesis-only request properties without affecting legacy work.
   */
  public static function validateRequest(string $operation, ?string $version, ?string $stage, ?string $lengthPreset): void {
    if ($operation !== self::OPERATION) {
      if ($version !== NULL || $stage !== NULL || $lengthPreset !== NULL) {
        throw new \InvalidArgumentException('Synthesis properties are only supported for release-synthesis requests.');
      }
      return;
    }
    if ($version !== self::VERSION) {
      throw new \InvalidArgumentException('Unknown release-synthesis contract version.');
    }
    if (!in_array($stage, [self::STAGE_INTERMEDIATE, self::STAGE_FINAL], TRUE)) {
      throw new \InvalidArgumentException('Unknown release-synthesis stage.');
    }
    if (!array_key_exists((string) $lengthPreset, self::FINAL_MAX_ITEMS)) {
      throw new \InvalidArgumentException('Unknown release-synthesis length preset.');
    }
  }

  /**
   * Returns the maximum number of items allowed for one synthesis result.
   */
  public static function maxItems(string $stage, string $lengthPreset): int {
    self::validateRequest(self::OPERATION, self::VERSION, $stage, $lengthPreset);
    return $stage === self::STAGE_INTERMEDIATE
      ? self::INTERMEDIATE_MAX_ITEMS
      : self::FINAL_MAX_ITEMS[$lengthPreset];
  }

  /**
   * Returns stage-specific instructions for a synthesis prompt.
   */
  public static function instructions(string $stage, string $lengthPreset): string {
    $maxItems = self::maxItems($stage, $lengthPreset);
    $common = 'You may group related evidence, prioritize significant recorded changes, count supported activity, and identify evidence-grounded themes. Every factual claim must cite its supporting evidence IDs. Do not infer unsupported intent, user impact, fixes, or security implications.';
    if ($stage === self::STAGE_INTERMEDIATE) {
      return "Synthesis stage: intermediate. Produce at most {$maxItems} reusable evidence-backed candidates for a later synthesis round. {$common}";
    }
    return "Synthesis stage: final. Produce at most {$maxItems} categorized changelog notes for the {$lengthPreset} length preset. {$common}";
  }

  /**
   * Builds the strict, versioned response schema for one synthesis stage.
   */
  public static function responseSchema(string $version, string $stage, string $lengthPreset): array {
    self::validateRequest(self::OPERATION, $version, $stage, $lengthPreset);
    return [
      'name' => "changelogify_synthesis_{$stage}_v{$version}",
      'description' => $stage === self::STAGE_INTERMEDIATE
        ? 'Evidence-backed intermediate candidates for later Changelogify synthesis.'
        : 'Evidence-backed categorized notes for a Changelogify release.',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'required' => ['status', 'items', 'omitted_source_ids', 'warnings'],
        'properties' => [
          'status' => ['type' => 'string', 'enum' => ['completed', 'partial', 'refused']],
          'items' => [
            'type' => 'array',
            'maxItems' => self::maxItems($stage, $lengthPreset),
            'items' => [
              'type' => 'object',
              'additionalProperties' => FALSE,
              'required' => ['id', 'section', 'text', 'source_ids'],
              'properties' => [
                'id' => ['type' => 'string'],
                'section' => [
                  'type' => 'string',
                  'enum' => ['added', 'changed', 'fixed', 'removed', 'security', 'other'],
                ],
                'text' => ['type' => 'string', 'maxLength' => 2048],
                'source_ids' => [
                  'type' => 'array',
                  'minItems' => 1,
                  'items' => ['type' => 'string'],
                ],
              ],
            ],
          ],
          'omitted_source_ids' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
          ],
          'warnings' => [
            'type' => 'array',
            'maxItems' => 50,
            'items' => ['type' => 'string'],
          ],
        ],
      ],
    ];
  }

}
