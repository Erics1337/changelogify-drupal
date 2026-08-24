<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\ReleaseItemNormalizer;
use Drupal\Component\Uuid\UuidInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests release item normalization.
 *
 * @group changelogify
 */
#[Group('changelogify')]
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

  /**
   * Tests structured edits, movement, ordering, deletion, and manual items.
   */
  public function testStructuredItemsPreserveIdentity(): void {
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->expects(self::once())->method('generate')->willReturn('manual-id');
    $normalizer = new ReleaseItemNormalizer($uuid);
    $existing = [
      'added' => [
        ['id' => 'first-id', 'text' => 'First', 'event_ids' => [1]],
        ['id' => 'deleted-id', 'text' => 'Delete', 'event_ids' => [2]],
      ],
      'changed' => [
        ['id' => 'second-id', 'text' => 'Second', 'event_ids' => [3]],
      ],
    ];
    $sections = $normalizer->fromStructured([
      [
        'id' => 'first-id',
        'text' => 'First edited',
        'section' => 'fixed',
        'order' => 20,
      ],
      [
        'id' => 'deleted-id',
        'text' => 'Delete',
        'section' => 'added',
        'order' => 0,
        'remove' => 1,
      ],
      [
        'id' => 'second-id',
        'text' => 'Second',
        'section' => 'fixed',
        'order' => 10,
      ],
      [
        'id' => '',
        'text' => 'Manual',
        'section' => 'fixed',
        'order' => 15,
      ],
    ], $existing);

    self::assertSame(['second-id', 'manual-id', 'first-id'], array_column($sections['fixed'], 'id'));
    self::assertSame(['Second', 'Manual', 'First edited'], array_column($sections['fixed'], 'text'));
    self::assertSame([3], $sections['fixed'][0]['event_ids']);
    self::assertSame([], $sections['fixed'][1]['event_ids']);
    self::assertSame([1], $sections['fixed'][2]['event_ids']);
    self::assertSame([], $sections['added']);
  }

  /**
   * Tests client-supplied item identifiers are rejected.
   */
  public function testStructuredItemsRejectUnknownIdentifier(): void {
    $normalizer = new ReleaseItemNormalizer($this->createStub(UuidInterface::class));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('stale or invalid');
    $normalizer->fromStructured([
      [
        'id' => 'forged-id',
        'text' => 'Forged',
        'section' => 'added',
        'order' => 0,
      ],
    ], []);
  }

}
