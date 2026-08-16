<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\ReleaseItemNormalizer;
use Drupal\Component\Uuid\UuidInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests release item normalization.
 *
 * @group changelogify
 */
final class ReleaseItemNormalizerTest extends TestCase {

  /**
   * Tests existing item identifiers and source events are retained.
   */
  public function testExistingProvenanceIsPreserved(): void {
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects(self::once())
      ->method('generate')
      ->willReturn('new-item-id');

    $normalizer = new ReleaseItemNormalizer($uuid);
    $existing = [
          [
            'id' => 'first-id',
            'text' => 'Repeated item',
            'event_ids' => [11],
          ],
          [
            'id' => 'second-id',
            'text' => 'Repeated item',
            'event_ids' => [12, 13],
          ],
    ];

    $items = $normalizer->fromText(
          " Repeated item\r\nRepeated item\n0\n\n",
          $existing,
      );

    self::assertSame([
          [
            'id' => 'first-id',
            'text' => 'Repeated item',
            'event_ids' => [11],
          ],
          [
            'id' => 'second-id',
            'text' => 'Repeated item',
            'event_ids' => [12, 13],
          ],
          [
            'id' => 'new-item-id',
            'text' => '0',
            'event_ids' => [],
          ],
    ], $items);
  }

}
