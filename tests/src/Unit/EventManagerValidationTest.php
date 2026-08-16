<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\EventManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests validation at the event logging boundary.
 */
#[Group('changelogify')]
final class EventManagerValidationTest extends TestCase {

  /**
   * Tests invalid event input is rejected before persistence.
   */
  #[DataProvider('invalidEventDataProvider')]
  public function testInvalidEventDataIsRejected(array $data): void {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects(self::never())->method('getStorage');

    $manager = new EventManager(
          $entityTypeManager,
          $this->createMock(AccountProxyInterface::class),
          $this->createMock(TimeInterface::class),
      );

    $this->expectException(\InvalidArgumentException::class);
    $manager->logEvent($data);
  }

  /**
   * Provides malformed event payloads.
   */
  public static function invalidEventDataProvider(): array {
    $valid = [
      'event_type' => 'content_created',
      'source' => 'content_entity',
      'message' => 'Created a page.',
    ];

    return [
      'missing required key' => [array_diff_key($valid, ['message' => TRUE])],
      'blank required value' => [array_replace($valid, ['message' => '   '])],
      'metadata must be an array' => [$valid + ['metadata' => 'invalid']],
      'unknown release section' => [$valid + ['section_hint' => 'future']],
    ];
  }

}
