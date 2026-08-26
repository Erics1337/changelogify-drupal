<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

/**
 * Deterministically partitions synthesis evidence into provider-safe batches.
 */
final class SynthesisBatcher {

  public const MAX_ITEMS = 100;
  public const MAX_BYTES = 32768;

  /**
   * Partitions evidence without reordering its stable keys.
   *
   * @param array<string, array<string, mixed>> $evidence
   *   Eligible, policy-filtered evidence keyed by stable ID.
   *
   * @return array<int, array<string, array<string, mixed>>>
   *   Ordered, non-empty evidence batches.
   */
  public function partition(array $evidence): array {
    $batches = [];
    $current = [];
    foreach ($evidence as $id => $document) {
      $candidate = $current + [$id => $document];
      if ($current !== [] && (count($candidate) > self::MAX_ITEMS || $this->bytes($candidate) > self::MAX_BYTES)) {
        $batches[] = $current;
        $current = [$id => $document];
      }
      else {
        $current = $candidate;
      }
    }
    if ($current !== []) {
      $batches[] = $current;
    }
    return $batches;
  }

  /**
   * Returns the exact serialized evidence bytes used for the batch bound.
   */
  public function bytes(array $evidence): int {
    return strlen(json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
  }

}
