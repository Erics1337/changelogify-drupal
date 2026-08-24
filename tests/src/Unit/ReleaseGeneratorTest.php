<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\ReleaseGenerator;
use Drupal\changelogify\ReleaseCoverageAnalyzer;
use Drupal\changelogify\EventReleaseUsage;
use Drupal\changelogify\ChangeSet\ChangeSetAggregatorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests bounded release generation.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class ReleaseGeneratorTest extends TestCase {

  /**
   * Tests an overly broad range is rejected before release persistence.
   */
  public function testEventLimitIsEnforced(): void {
    $start = new \DateTimeImmutable('2025-01-01 00:00:00 UTC');
    $end = new \DateTimeImmutable('2025-01-31 23:59:59 UTC');

    $eventManager = $this->createMock(EventManagerInterface::class);
    $eventManager->expects(self::once())
      ->method('getEventsByRange')
      ->with($start, $end, ['limit' => 5001])
      ->willReturn(array_fill(0, 5001, new \stdClass()));

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects(self::never())->method('getStorage');

    $generator = new ReleaseGenerator(
      $entityTypeManager,
      $eventManager,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(ChangeSetAggregatorInterface::class),
      new ReleaseCoverageAnalyzer(
        $entityTypeManager,
        new EventReleaseUsage($entityTypeManager),
      ),
    );

    $this->expectException(\LengthException::class);
    $this->expectExceptionMessage('at most 5000 events');
    $generator->generateReleaseFromRange($start, $end);
  }

}
