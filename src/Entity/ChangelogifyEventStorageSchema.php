<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines indexes for event range and filter queries.
 */
final class ChangelogifyEventStorageSchema extends SqlContentEntityStorageSchema
{

    /**
     * {@inheritdoc}
     */
    protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array
    {
        $schema = parent::getEntitySchema($entity_type, $reset);
        $table = $this->storage->getBaseTable();

        $schema[$table]['indexes'] += [
            'changelogify_event__timestamp' => ['timestamp'],
            'changelogify_event__event_type_timestamp' => ['event_type', 'timestamp'],
            'changelogify_event__source_timestamp' => ['source', 'timestamp'],
            'changelogify_event__section_timestamp' => ['section_hint', 'timestamp'],
        ];

        return $schema;
    }

}
