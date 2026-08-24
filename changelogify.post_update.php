<?php

/**
 * @file
 * Post-update functions for Changelogify.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\Utility\UpdateException;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;

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

/**
 * Adds normalized contract provenance fields and correlation indexes.
 */
function changelogify_post_update_add_event_contract_fields(): void {
  $database = Database::getConnection();
  $schema = $database->schema();
  if (!$schema->tableExists('changelogify_event')) {
    $message = 'Changelogify cannot add event contract fields because the changelogify_event table is missing. Restore the module database table from backup, then rerun database updates.';
    \Drupal::logger('changelogify')->error($message);
    throw new UpdateException($message);
  }
  $updateManager = \Drupal::entityDefinitionUpdateManager();
  $fieldManager = \Drupal::service('entity_field.manager');
  $definitions = $fieldManager->getBaseFieldDefinitions('changelogify_event');

  foreach (['schema_version', 'correlation_id'] as $fieldName) {
    if (!isset($definitions[$fieldName])) {
      $message = sprintf('Changelogify cannot add the %s event field because its definition is unavailable. Restore the module code and rerun database updates.', $fieldName);
      \Drupal::logger('changelogify')->error($message);
      throw new UpdateException($message);
    }
    if ($updateManager->getFieldStorageDefinition($fieldName, 'changelogify_event') === NULL) {
      $updateManager->installFieldStorageDefinition(
        $fieldName,
        'changelogify',
        'changelogify_event',
        $definitions[$fieldName],
      );
    }
  }

  $database->update('changelogify_event')
    ->fields(['schema_version' => 1])
    ->isNull('schema_version')
    ->execute();

  $tableSpec = [
    'fields' => [
      'timestamp' => ['type' => 'int', 'not null' => TRUE],
      'schema_version' => ['type' => 'int', 'not null' => TRUE, 'default' => 1],
      'correlation_id' => ['type' => 'varchar', 'length' => 128],
    ],
  ];
  $indexes = [
    'changelogify_event__correlation_timestamp' => ['correlation_id', 'timestamp'],
    'changelogify_event__schema_timestamp' => ['schema_version', 'timestamp'],
  ];
  foreach ($indexes as $name => $fields) {
    if (!$schema->indexExists('changelogify_event', $name)) {
      try {
        $schema->addIndex('changelogify_event', $name, $fields, $tableSpec);
      }
      catch (\Throwable $exception) {
        $message = sprintf('Changelogify could not create the %s index. Correct the database schema problem and rerun database updates. Database error: %s', $name, $exception->getMessage());
        \Drupal::logger('changelogify')->error($message);
        throw new UpdateException($message, 0, $exception);
      }
    }
  }

  \Drupal::logger('changelogify')->notice('Added the versioned event contract fields and indexes without changing existing events.');
}

/**
 * Adds and safely backfills privacy-bounded release provenance.
 */
function changelogify_post_update_add_release_provenance(): void {
  $updateManager = \Drupal::entityDefinitionUpdateManager();
  if ($updateManager->getFieldStorageDefinition('provenance', 'changelogify_release') === NULL) {
    $definitions = \Drupal::service('entity_field.manager')
      ->getBaseFieldDefinitions('changelogify_release');
    $updateManager->installFieldStorageDefinition(
      'provenance',
      'changelogify',
      'changelogify_release',
      $definitions['provenance'],
    );
  }

  $count = \Drupal::service(ReleaseProvenanceManagerInterface::class)
    ->backfillExistingReleases();
  \Drupal::logger('changelogify')->notice('Backfilled minimal provenance for @count releases.', [
    '@count' => $count,
  ]);
}
