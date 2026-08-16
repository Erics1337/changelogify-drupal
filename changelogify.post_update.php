<?php

/**
 * @file
 * Post-update functions for Changelogify.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;

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

  foreach ($tables as $table => $tableSpec) {
    if (!$schema->tableExists($table)) {
      continue;
    }

    foreach ($tableSpec['indexes'] as $name => $fields) {
      if (!$schema->indexExists($table, $name)) {
        $schema->addIndex($table, $name, $fields, $tableSpec);
      }
    }
  }
}
