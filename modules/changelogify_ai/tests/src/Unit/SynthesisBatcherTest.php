<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\changelogify_ai\SynthesisBatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests deterministic item-count and serialized-byte synthesis bounds.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisBatcherTest extends TestCase {

  /**
   * Item limits create stable batches without reordering evidence.
   */
  public function testPartitionsByItemLimitDeterministically(): void {
    $evidence = [];
    for ($index = 1; $index <= 250; $index++) {
      $evidence["change-{$index}"] = ['summary' => "Change {$index}."];
    }
    $batcher = new SynthesisBatcher();
    $first = $batcher->partition($evidence);
    self::assertSame($first, $batcher->partition($evidence));
    self::assertSame([100, 100, 50], array_map('count', $first));
    self::assertSame(array_keys($evidence), array_merge(...array_map('array_keys', $first)));
  }

  /**
   * Serialized evidence bytes split batches below the count limit.
   */
  public function testPartitionsBySerializedByteLimit(): void {
    $evidence = [
      'large-1' => ['summary' => str_repeat('a', 20000)],
      'large-2' => ['summary' => str_repeat('b', 20000)],
      'small' => ['summary' => 'Small evidence.'],
    ];
    $batcher = new SynthesisBatcher();
    $batches = $batcher->partition($evidence);
    self::assertCount(2, $batches);
    self::assertSame(['large-1'], array_keys($batches[0]));
    self::assertSame(['large-2', 'small'], array_keys($batches[1]));
    foreach ($batches as $batch) {
      self::assertLessThanOrEqual(SynthesisBatcher::MAX_BYTES, $batcher->bytes($batch));
    }
  }

}
