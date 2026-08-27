<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify_ai\Summarization\SummarizationResult;
use Drupal\changelogify_ai\Summarization\SummarizationRequest;
use Drupal\changelogify_ai\Summarization\SynthesisContract;

/**
 * Enforces provider-neutral, evidence-aware output bounds.
 */
final class ResultValidator {
  private const SECTIONS = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];
  private const MAX_RESPONSE_BYTES = 65536;

  /**
   * Validates a normalized result before it can affect a release.
   *
   * @param \Drupal\changelogify_ai\Summarization\SummarizationResult $result
   *   Normalized provider result.
   * @param string[] $allowedSourceIds
   *   Evidence IDs selected for this operation.
   * @param \Drupal\changelogify_ai\Summarization\SummarizationRequest|null $request
   *   Request contract supplying synthesis-specific bounds, when applicable.
   */
  public function validate(SummarizationResult $result, array $allowedSourceIds, ?SummarizationRequest $request = NULL): void {
    if (!in_array($result->status, ['completed', 'refused', 'partial'], TRUE)) {
      throw new \UnexpectedValueException('Unknown AI result state.');
    }
    $maxItems = $request !== NULL && $request->operation === SynthesisContract::OPERATION
      ? SynthesisContract::maxItems((string) $request->getSynthesisStage(), (string) $request->getLengthPreset())
      : 200;
    if (count($result->items) > $maxItems || count($result->warnings) > 50) {
      throw new \LengthException('AI response exceeds configured bounds.');
    }
    $responseBytes = strlen(json_encode([
      'status' => $result->status,
      'items' => array_map(static fn ($item): array => [
        'id' => $item->id,
        'section' => $item->section,
        'text' => $item->text,
        'source_ids' => $item->sourceIds,
      ], $result->items),
      'omitted_source_ids' => $result->omittedSourceIds,
      'warnings' => $result->warnings,
    ], JSON_THROW_ON_ERROR));
    if ($responseBytes > self::MAX_RESPONSE_BYTES) {
      throw new \LengthException('AI response exceeds the total size limit.');
    }
    $ids = [];
    $citedSourceIds = [];
    foreach ($result->items as $item) {
      if (isset($ids[$item->id]) || !preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $item->id)) {
        throw new \UnexpectedValueException('AI response has duplicate or invalid item IDs.');
      }
      $ids[$item->id] = TRUE;
      if (!in_array($item->section, self::SECTIONS, TRUE) || mb_strlen($item->text) > 2048 || trim($item->text) === '' || preg_match('/<[^>]+>/', $item->text)) {
        throw new \UnexpectedValueException('AI response contains invalid item text or section.');
      }
      if ($item->sourceIds === [] || array_diff($item->sourceIds, $allowedSourceIds) !== []) {
        throw new \UnexpectedValueException('AI response references unknown evidence.');
      }
      $citedSourceIds = array_merge($citedSourceIds, $item->sourceIds);
    }
    if (array_diff($result->omittedSourceIds, $allowedSourceIds) !== []) {
      throw new \UnexpectedValueException('AI response omits unknown evidence.');
    }
    if ($request !== NULL) {
      $requiredOmissions = PromptTemplateRegistry::requiredOmittedSourceIds($request);
      if (array_intersect($requiredOmissions, $citedSourceIds) !== []
        || array_diff($requiredOmissions, $result->omittedSourceIds) !== []) {
        throw new \UnexpectedValueException('AI response violates mandatory editorial evidence omissions.');
      }
    }
  }

}
