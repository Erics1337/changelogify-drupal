<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\Core\Database\Database;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the current clean-install contract.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class CleanInstallKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests default configuration, typed schema, fields, and indexes.
   */
  public function testCleanInstallation(): void {
    $settings = $this->config('changelogify.settings')->getRawData();
    unset($settings['_core']);
    self::assertSame([
      'changelog_path' => '/changelog',
      'track_content' => TRUE,
      'track_unpublished_content' => FALSE,
      'track_modules' => TRUE,
      'track_users' => FALSE,
      'event_retention_days' => 90,
    ], $settings);

    $typedConfig = $this->container->get('config.typed')
      ->get('changelogify.settings');
    self::assertSame('/changelog', $typedConfig->get('changelog_path')->getValue());
    self::assertTrue($typedConfig->get('track_content')->getValue());
    self::assertFalse($typedConfig->get('track_unpublished_content')->getValue());
    self::assertSame(90, $typedConfig->get('event_retention_days')->getValue());

    $fieldManager = $this->container->get('entity_field.manager');
    self::assertEqualsCanonicalizing([
      'id', 'uuid', 'timestamp', 'event_type', 'source', 'entity_type_id',
      'entity_id', 'bundle', 'user_id', 'message', 'metadata', 'section_hint',
    ], array_keys($fieldManager->getBaseFieldDefinitions('changelogify_event')));
    self::assertEqualsCanonicalizing([
      'id', 'uuid', 'title', 'label_type', 'version', 'release_date',
      'date_start', 'date_end', 'sections', 'status', 'uid', 'created',
      'changed',
    ], array_keys($fieldManager->getBaseFieldDefinitions('changelogify_release')));

    $schema = Database::getConnection()->schema();
    self::assertTrue($schema->indexExists('changelogify_event', 'changelogify_event__timestamp'));
    self::assertTrue($schema->indexExists('changelogify_event', 'changelogify_event__event_type_timestamp'));
    self::assertTrue($schema->indexExists('changelogify_event', 'changelogify_event__source_timestamp'));
    self::assertTrue($schema->indexExists('changelogify_event', 'changelogify_event__section_timestamp'));
    self::assertTrue($schema->indexExists('changelogify_release', 'changelogify_release__status_date'));
  }

}
