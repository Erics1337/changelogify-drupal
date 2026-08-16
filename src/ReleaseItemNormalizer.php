<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Uuid\UuidInterface;

/**
 * Converts editable release text while preserving item provenance.
 */
final class ReleaseItemNormalizer {

  public function __construct(
    private readonly UuidInterface $uuid,
  ) {
  }

  /**
   * Converts line-delimited text into structured release items.
   *
   * Existing items are matched by text in their original order. This keeps
   * their stable IDs and source event IDs when an editor changes other lines.
   *
   * @param string $text
   *   The line-delimited editable text.
   * @param array<int, array<string, mixed>> $existingItems
   *   The existing structured items for the section.
   *
   * @return array<int, array{id: string, text: string, event_ids: array}>
   *   The normalized release items.
   */
  public function fromText(string $text, array $existingItems = []): array {
    $existingByText = [];
    foreach ($existingItems as $item) {
      $itemText = trim((string) ($item['text'] ?? ''));
      if ($itemText !== '') {
        $existingByText[$itemText][] = $item;
      }
    }

    $lines = array_map('trim', preg_split('/\R/u', $text) ?: []);
    $lines = array_values(array_filter(
          $lines,
          static fn (string $line): bool => $line !== '',
      ));

    $items = [];
    foreach ($lines as $line) {
      $existing = isset($existingByText[$line])
                ? array_shift($existingByText[$line])
                : NULL;
      $items[] = [
        'id' => (string) ($existing['id'] ?? $this->uuid->generate()),
        'text' => $line,
        'event_ids' => array_values($existing['event_ids'] ?? []),
      ];
    }

    return $items;
  }

}
