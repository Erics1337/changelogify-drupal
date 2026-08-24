<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Utility\UpdateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests supported historical update paths.
 *
 * @group changelogify
 * @runTestsInSeparateProcesses
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class UpdatePathKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests every supported configuration and storage starting state.
   *
   * @dataProvider historicalStateProvider
   */
  #[DataProvider('historicalStateProvider')]
  public function testHistoricalStateUpgrade(string $version, array $settings): void {
    $this->config('changelogify.settings')->setData($settings)->save();
    $fixture = $this->createHistoricalData($version);
    $this->dropQueryIndexes();

    $this->runAllUpdates();
    $this->assertCurrentSettings($settings);
    $this->assertQueryIndexes();
    $this->assertHistoricalData($fixture);

    // Simulate an interrupted batch where some indexes already exist.
    Database::getConnection()->schema()->dropIndex(
      'changelogify_event',
      'changelogify_event__source_timestamp',
    );
    $this->runAllUpdates();
    $this->assertCurrentSettings($settings);
    $this->assertQueryIndexes();
    $this->assertHistoricalData($fixture);
  }

  /**
   * Tests schema corruption produces recovery guidance.
   */
  public function testMissingTableFailureIsActionable(): void {
    Database::getConnection()->schema()->dropTable('changelogify_release');
    $this->includeUpdateFiles();

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Restore the module database tables from backup, then rerun database updates.');
    changelogify_post_update_ensure_query_indexes();
    changelogify_post_update_add_event_contract_fields();
  }

  /**
   * Provides real configuration shapes from supported releases.
   */
  public static function historicalStateProvider(): array {
    return [
      '1.1 without stored settings' => ['1.1', []],
      '1.2 defaults and administrator override' => ['1.2', [
        'track_content' => TRUE,
        'track_modules' => TRUE,
        'track_users' => FALSE,
        'event_retention_days' => 0,
      ],
      ],
      '1.3 beta settings' => ['1.3-beta', [
        'changelog_path' => '/updates',
        'track_content' => FALSE,
        'track_unpublished_content' => TRUE,
        'track_modules' => TRUE,
        'track_users' => TRUE,
        'event_retention_days' => 365,
      ],
      ],
    ];
  }

  /**
   * Creates records shaped like supported historical releases.
   */
  private function createHistoricalData(string $version): array {
    $event = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_event')
      ->create([
        'timestamp' => 1_700_000_000,
        'event_type' => 'content_updated',
        'source' => 'content_entity',
        'message' => "Historical event from $version",
        'metadata' => json_encode([
          'version' => $version,
          'path' => '/historical-page',
        ], JSON_THROW_ON_ERROR),
        'section_hint' => 'changed',
      ]);
    $event->save();

    $sections = [
      'added' => [],
      'changed' => [[
        'id' => "historical-$version",
        'text' => "Preserved release item from $version",
        'event_ids' => [(int) $event->id()],
      ],
      ],
      'fixed' => [],
      'removed' => [],
      'security' => [],
      'other' => [],
    ];
    $release = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->create([
        'title' => "Historical $version release",
        'release_date' => 1_700_000_001,
        'sections' => json_encode($sections, JSON_THROW_ON_ERROR),
        'status' => FALSE,
        'uid' => 0,
      ]);
    $release->save();

    return [
      'event_id' => $event->id(),
      'metadata' => $event->get('metadata')->value,
      'release_id' => $release->id(),
      'sections' => $release->get('sections')->value,
    ];
  }

  /**
   * Asserts historical JSON payloads and records survive exactly.
   */
  private function assertHistoricalData(array $fixture): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $event = $entityTypeManager->getStorage('changelogify_event')
      ->load($fixture['event_id']);
    $release = $entityTypeManager->getStorage('changelogify_release')
      ->load($fixture['release_id']);

    self::assertNotNull($event);
    self::assertNotNull($release);
    self::assertSame($fixture['metadata'], $event->get('metadata')->value);
    self::assertSame(1, (int) $event->get('schema_version')->value);
    self::assertNull($event->get('correlation_id')->value);
    self::assertSame($fixture['sections'], $release->get('sections')->value);
  }

  /**
   * Runs all lifecycle updates in their supported order.
   */
  private function runAllUpdates(): void {
    $this->includeUpdateFiles();
    changelogify_update_12001();
    changelogify_update_13001();
    changelogify_post_update_ensure_query_indexes();
    changelogify_post_update_add_event_contract_fields();
  }

  /**
   * Loads update hooks once for direct kernel execution.
   */
  private function includeUpdateFiles(): void {
    $modulePath = $this->container
      ->get('extension.list.module')
      ->getPath('changelogify');
    require_once DRUPAL_ROOT . '/' . $modulePath . '/changelogify.install';
    require_once DRUPAL_ROOT . '/' . $modulePath . '/changelogify.post_update.php';
  }

  /**
   * Removes indexes that were absent from historical schemas.
   */
  private function dropQueryIndexes(): void {
    $schema = Database::getConnection()->schema();
    foreach ($this->queryIndexes() as $table => $indexes) {
      foreach ($indexes as $index) {
        $schema->dropIndex($table, $index);
      }
    }
  }

  /**
   * Asserts all current query indexes exist.
   */
  private function assertQueryIndexes(): void {
    $schema = Database::getConnection()->schema();
    foreach ($this->queryIndexes() as $table => $indexes) {
      foreach ($indexes as $index) {
        self::assertTrue($schema->indexExists($table, $index), "$index exists");
      }
    }
  }

  /**
   * Returns query indexes required by the current schema.
   */
  private function queryIndexes(): array {
    return [
      'changelogify_event' => [
        'changelogify_event__timestamp',
        'changelogify_event__event_type_timestamp',
        'changelogify_event__source_timestamp',
        'changelogify_event__section_timestamp',
        'changelogify_event__correlation_timestamp',
        'changelogify_event__schema_timestamp',
      ],
      'changelogify_release' => [
        'changelogify_release__status_date',
      ],
    ];
  }

  /**
   * Asserts update defaults while preserving historical values.
   */
  private function assertCurrentSettings(array $original): void {
    $expected = $original + [
      'track_content' => TRUE,
      'track_modules' => FALSE,
      'track_users' => FALSE,
      'event_retention_days' => 0,
      'track_unpublished_content' => FALSE,
      'changelog_path' => '/changelog',
    ];
    self::assertEquals($expected, $this->config('changelogify.settings')->getRawData());
  }

}
