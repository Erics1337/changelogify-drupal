<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\Component\Uuid\UuidInterface;

/**
 * Converts editable release text while preserving item provenance.
 */
final class ReleaseItemNormalizer {

  private const SECTIONS = ['added', 'changed', 'fixed', 'removed', 'security', 'other'];

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

  /**
   * Normalizes structured editor values against stored item identities.
   *
   * @param array $submittedItems
   *   Submitted structured item values.
   * @param array $existingSections
   *   Stored release sections used as the identity and evidence authority.
   *
   * @return array<string, array<int, array{id: string, text: string, event_ids: array}>>
   *   Normalized sections in stable submitted order.
   */
  public function fromStructured(array $submittedItems, array $existingSections): array {
    $existingById = [];
    foreach ($existingSections as $items) {
      foreach ($items as $item) {
        $id = (string) ($item['id'] ?? '');
        if ($id === '' || isset($existingById[$id])) {
          throw new \InvalidArgumentException('Stored release items contain an invalid or duplicate identifier.');
        }
        $existingById[$id] = $item;
      }
    }

    $seen = [];
    $normalized = array_fill_keys(self::SECTIONS, []);
    foreach (array_values($submittedItems) as $position => $submitted) {
      if (!is_array($submitted) || !empty($submitted['remove'])) {
        continue;
      }
      $text = trim((string) ($submitted['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $section = (string) ($submitted['section'] ?? '');
      if (!in_array($section, self::SECTIONS, TRUE)) {
        throw new \InvalidArgumentException('A release item has an invalid section.');
      }
      $id = trim((string) ($submitted['id'] ?? ''));
      if ($id === '') {
        $id = $this->uuid->generate();
        $eventIds = [];
      }
      else {
        if (!isset($existingById[$id])) {
          throw new \InvalidArgumentException('A release item identifier is stale or invalid.');
        }
        $eventIds = array_values($existingById[$id]['event_ids'] ?? []);
      }
      if (isset($seen[$id])) {
        throw new \InvalidArgumentException('A release item identifier was submitted more than once.');
      }
      $seen[$id] = TRUE;
      $order = filter_var($submitted['order'] ?? NULL, FILTER_VALIDATE_INT);
      $normalized[$section][] = [
        'id' => $id,
        'text' => $text,
        'event_ids' => $eventIds,
        '_order' => $order === FALSE ? $position : $order,
        '_position' => $position,
      ];
    }
    foreach ($normalized as &$items) {
      usort($items, static fn (array $left, array $right): int => [
        $left['_order'],
        $left['_position'],
      ] <=> [
        $right['_order'],
        $right['_position'],
      ]);
      foreach ($items as &$item) {
        unset($item['_order'], $item['_position']);
      }
      unset($item);
    }
    unset($items);
    return $normalized;
  }

}
