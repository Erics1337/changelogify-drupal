<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\EventSource\EventSourceInterface;
use Drupal\changelogify\EventSource\EventSourceRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests event-source discovery and validation.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class EventSourceRegistryTest extends TestCase {

  /**
   * Tests discovered sources are keyed and sorted by ID.
   */
  public function testSourceDiscovery(): void {
    $registry = new EventSourceRegistry([
      $this->source('users'),
      $this->source('content'),
    ]);

    self::assertSame(['content', 'users'], array_keys($registry->getSources()));
    self::assertSame('users', $registry->getSource('users')->getId());
  }

  /**
   * Tests duplicate IDs fail clearly.
   */
  public function testDuplicateSourceIdsFailClearly(): void {
    $registry = new EventSourceRegistry([
      $this->source('content'),
      $this->source('content'),
    ]);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Duplicate Changelogify event source ID "content".');
    $registry->getSources();
  }

  /**
   * Creates a minimal source double.
   */
  private function source(string $id): EventSourceInterface {
    $source = $this->createMock(EventSourceInterface::class);
    $source->method('getId')->willReturn($id);
    return $source;
  }

}
