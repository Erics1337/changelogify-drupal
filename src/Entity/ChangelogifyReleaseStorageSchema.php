<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines indexes for published release queries.
 */
final class ChangelogifyReleaseStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $dataTable = $this->storage->getDataTable();
    foreach ($schema as $tableName => &$table) {
      if (isset($table['fields']['status'])) {
        $table['indexes']['changelogify_release__status'] = ['status'];
      }
      if (isset($table['fields']['release_date'])) {
        $table['indexes']['changelogify_release__release_date'] = ['release_date'];
      }
      if (isset($table['fields']['scheduled_at'])) {
        $table['indexes']['changelogify_release__scheduled_at'] = ['scheduled_at'];
      }
      if ($tableName === $dataTable && isset($table['fields']['langcode'], $table['fields']['slug'])) {
        $table['unique keys']['changelogify_release__langcode_slug'] = ['langcode', 'slug'];
      }
    }
    unset($table);

    return $schema;
  }

}
