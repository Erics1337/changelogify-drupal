<?php

/**
 * @file
 * Post-update functions for Changelogify.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\Utility\UpdateException;

/**
 * Adds indexes used by event range and published release queries.
 */
function changelogify_post_update_ensure_query_indexes(): void {
  $schema = Database::getConnection()->schema();
  $tables = [
    'changelogify_event' => [
      'fields' => [
        'timestamp' => ['type' => 'int', 'not null' => TRUE],
        'event_type' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
        'source' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
        'section_hint' => ['type' => 'varchar', 'length' => 32],
      ],
      'indexes' => [
        'changelogify_event__timestamp' => ['timestamp'],
        'changelogify_event__event_type_timestamp' => ['event_type', 'timestamp'],
        'changelogify_event__source_timestamp' => ['source', 'timestamp'],
        'changelogify_event__section_timestamp' => ['section_hint', 'timestamp'],
      ],
    ],
    'changelogify_release' => [
      'fields' => [
        'status' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE],
        'release_date' => ['type' => 'int'],
      ],
      'indexes' => [
        'changelogify_release__status_date' => ['status', 'release_date'],
      ],
    ],
  ];

  $missingTables = array_filter(
    array_keys($tables),
    static fn (string $table): bool => !$schema->tableExists($table),
  );
  if ($missingTables) {
    $message = sprintf(
      'Changelogify cannot add query indexes because required tables are missing: %s. Restore the module database tables from backup, then rerun database updates.',
      implode(', ', $missingTables),
    );
    \Drupal::logger('changelogify')->error($message);
    throw new UpdateException($message);
  }

  foreach ($tables as $table => $tableSpec) {

    foreach ($tableSpec['indexes'] as $name => $fields) {
      if (!$schema->indexExists($table, $name)) {
        try {
          $schema->addIndex($table, $name, $fields, $tableSpec);
        }
        catch (\Throwable $exception) {
          $message = sprintf(
            'Changelogify could not create the %s index on %s. Correct the database schema problem and rerun database updates. Database error: %s',
            $name,
            $table,
            $exception->getMessage(),
          );
          \Drupal::logger('changelogify')->error($message);
          throw new UpdateException($message, 0, $exception);
        }
      }
    }
  }

  \Drupal::logger('changelogify')->notice('Verified all Changelogify event and release query indexes.');
}
