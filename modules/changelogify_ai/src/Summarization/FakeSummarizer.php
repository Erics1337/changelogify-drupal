<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * Deterministic test summarizer; it never makes a network request.
 */
final class FakeSummarizer implements SummarizerInterface {

  public function __construct(private readonly string $mode = 'success') {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->mode !== 'missing_capability';
  }

  /**
   * {@inheritdoc}
   */
  public function selectedProviderModel(): ?array {
    return $this->isAvailable() ? ['provider' => 'fake', 'model' => 'deterministic'] : NULL;
  }

  /**
   * Creates a deterministic test double in the selected response mode.
   */
  public function summarize(SummarizationRequest $request): SummarizationResult {
    if ($this->mode === 'refusal') {
      return $this->refuse();
    }
    match ($this->mode) {
      'failure' => $this->fail('Transient fake provider failure.'),
      'timeout' => $this->fail('Fake provider timed out.'),
      'missing_capability' => $this->unavailable(),
      default => NULL,
    };
    if ($this->mode === 'malformed') {
      return new SummarizationResult('completed', [
        new SummarizationItem('invalid', 'other', '<script>unsafe</script>', ['unknown']),
      ]);
    }
    if ($this->mode === 'empty') {
      return new SummarizationResult('completed', [], [], [], 'fake', 'deterministic');
    }
    $items = [];
    foreach ($request->evidence as $id => $evidence) {
      $items[] = new SummarizationItem($id, (string) ($evidence['section'] ?? 'other'), (string) ($evidence['summary'] ?? ''), [$id]);
    }
    if ($this->mode === 'completed_with_report') {
      return new SummarizationResult('completed', $items, array_keys($request->evidence), ['Deterministic provider warning.'], 'fake', 'deterministic');
    }
    return new SummarizationResult('completed', $items, [], [], 'fake', 'deterministic');
  }

  /**
   * Returns a deterministic provider refusal.
   */
  private function refuse(): SummarizationResult {
    return new SummarizationResult('refused');
  }

  /**
   * Throws a deterministic transient provider failure.
   */
  private function fail(string $message): never {
    throw new TransientSummarizationException($message);
  }

  /**
   * Throws a deterministic missing-capability error.
   */
  private function unavailable(): never {
    throw new ProviderUnavailableException('Fake provider lacks required chat capability.');
  }

}
