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
    $table = $this->storage->getBaseTable();

    $schema[$table]['indexes'] += [
      'changelogify_release__status_date' => ['status', 'release_date'],
    ];

    return $schema;
  }

}
