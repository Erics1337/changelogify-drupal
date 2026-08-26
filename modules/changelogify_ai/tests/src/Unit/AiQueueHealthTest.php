<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\changelogify_ai\AiQueueHealth;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests synthesis processor heartbeat and scheduling decisions.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class AiQueueHealthTest extends UnitTestCase {

  /**
   * Queue health distinguishes scheduled, delayed, and available processing.
   */
  #[DataProvider('healthCases')]
  public function testProcessorState(array $heartbeats, int $interval, int $now, int $created, string $expected, bool $delayed): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn (string $key, mixed $default = NULL): int => (int) ($heartbeats[$key] ?? $default ?? 0),
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('interval')->willReturn($interval);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('automated_cron.settings')->willReturn($config);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('automated_cron')->willReturn($interval > 0);

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->with('queue')->willReturn(TRUE);
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn([
      'queued_count' => 1,
      'oldest_created' => $created,
    ]);
    $query = $this->createMock(SelectInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->method('select')->willReturn($query);

    $health = new AiQueueHealth($state, $database, $time, $configFactory, $moduleHandler);
    $result = $health->status($created);
    self::assertSame($expected, $result['processor_state']);
    self::assertSame($delayed, $result['delayed']);
  }

  /**
   * Provides representative production queue-health states.
   */
  public static function healthCases(): array {
    return [
      'request-driven cron is scheduled' => [
        ['changelogify_ai.last_cron' => 1000],
        10800,
        2000,
        1900,
        'scheduled',
        FALSE,
      ],
      'queue is delayed without processing' => [
        ['system.cron_last' => 500],
        0,
        3000,
        1000,
        'delayed',
        TRUE,
      ],
      'dedicated runner is available' => [
        ['changelogify_ai.last_synthesis_runner' => 1995],
        0,
        2000,
        1900,
        'active',
        FALSE,
      ],
      'stale runner no longer looks available' => [
        ['changelogify_ai.last_synthesis_runner' => 1500],
        0,
        3000,
        1000,
        'delayed',
        TRUE,
      ],
    ];
  }

}
