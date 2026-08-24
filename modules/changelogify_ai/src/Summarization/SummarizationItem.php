<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai\Summarization;

/**
 * A proposed release item; text is always untrusted until rendered escaped.
 */
final class SummarizationItem {

  /**
   * Creates one proposed item.
   *
   * @param string $id
   *   Stable generated-item ID.
   * @param string $section
   *   Requested release section.
   * @param string $text
   *   Untrusted proposed text.
   * @param string[] $sourceIds
   *   Supporting evidence IDs.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $section,
    public readonly string $text,
    public readonly array $sourceIds,
  ) {}

}
