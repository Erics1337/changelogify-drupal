<?php

declare(strict_types=1);

namespace Drupal\changelogify\EntityDifference;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Produces deterministic, privacy-aware entity differences.
 */
final class EntityDifferenceService implements EntityDifferenceServiceInterface {

  private const IGNORED_FIELDS = [
    'changed',
    'created',
    'default_langcode',
    'revision_id',
    'revision_created',
    'revision_user',
    'revision_log_message',
    'revision_translation_affected',
  ];

  private const SCALAR_TYPES = [
    'boolean',
    'decimal',
    'float',
    'integer',
    'list_float',
    'list_integer',
    'list_string',
    'string',
  ];

  /**
   * {@inheritdoc}
   */
  public function compare(
    FieldableEntityInterface $updated,
    ?FieldableEntityInterface $original,
    array $scalarAllowlist = [],
  ): EntityDifference {
    if ($original === NULL) {
      return new EntityDifference();
    }

    $changedFields = [];
    $references = [];
    $scalarValues = [];
    $definitions = $updated->getFieldDefinitions();
    ksort($definitions);

    foreach ($definitions as $fieldName => $definition) {
      if (!$this->isComparable($fieldName, $definition)
        || !$original->hasField($fieldName)
        || !$updated->hasField($fieldName)) {
        continue;
      }
      $old = $original->get($fieldName)->getValue();
      $new = $updated->get($fieldName)->getValue();
      if ($this->normalizeItems($old) === $this->normalizeItems($new)) {
        continue;
      }

      if ($fieldName === 'status') {
        continue;
      }
      $changedFields[] = $fieldName;
      $fieldType = $definition->getType();
      if (in_array($fieldType, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
        $references[$fieldName] = [
          'old' => $this->referenceIds($old),
          'new' => $this->referenceIds($new),
        ];
      }
      elseif (in_array($fieldName, $scalarAllowlist, TRUE)
        && in_array($fieldType, self::SCALAR_TYPES, TRUE)
        && !$this->isSecretLike($fieldName)) {
        $scalarValues[$fieldName] = [
          'old' => $this->boundedScalar($old),
          'new' => $this->boundedScalar($new),
        ];
      }
    }

    sort($changedFields);
    ksort($references);
    ksort($scalarValues);
    return new EntityDifference(
      changedFields: $changedFields,
      publicationTransition: $this->publicationTransition($updated, $original),
      references: $references,
      scalarValues: $scalarValues,
    );
  }

  /**
   * Determines whether a field is safe and stable enough to compare.
   */
  private function isComparable(string $fieldName, FieldDefinitionInterface $definition): bool {
    return !$definition->isComputed()
      && !$definition->isReadOnly()
      && !in_array($fieldName, self::IGNORED_FIELDS, TRUE)
      && !str_starts_with($fieldName, 'content_translation_');
  }

  /**
   * Normalizes item arrays for deterministic comparisons.
   */
  private function normalizeItems(array $items): array {
    foreach ($items as &$item) {
      if (is_array($item)) {
        ksort($item);
      }
    }
    unset($item);
    return array_values($items);
  }

  /**
   * Extracts safe reference IDs without loading referenced entities.
   */
  private function referenceIds(array $items): array {
    $ids = [];
    foreach ($items as $item) {
      $id = $item['target_id'] ?? NULL;
      if (is_int($id) || (is_string($id) && strlen($id) <= 128)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /**
   * Extracts a bounded scalar or redacts the value.
   */
  private function boundedScalar(array $items): bool|float|int|string|null {
    if (count($items) !== 1 || !array_key_exists('value', $items[0])) {
      return NULL;
    }
    $value = $items[0]['value'];
    if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value)) {
      return NULL;
    }
    if (is_string($value) && strlen($value) > 128) {
      return '[redacted]';
    }
    return $value;
  }

  /**
   * Detects fields that should never expose values even when allowlisted.
   */
  private function isSecretLike(string $fieldName): bool {
    return preg_match('/(?:password|passwd|secret|token|api_key|private_key)/i', $fieldName) === 1;
  }

  /**
   * Returns one publication transition independently from changed fields.
   */
  private function publicationTransition(
    FieldableEntityInterface $updated,
    FieldableEntityInterface $original,
  ): ?string {
    if (!$updated->hasField('status') || !$original->hasField('status')) {
      return NULL;
    }
    $newValue = $updated->get('status')->getValue()[0]['value'] ?? NULL;
    $oldValue = $original->get('status')->getValue()[0]['value'] ?? NULL;
    if (!is_bool($newValue) && !is_int($newValue) && !is_string($newValue)) {
      return NULL;
    }
    if (!is_bool($oldValue) && !is_int($oldValue) && !is_string($oldValue)) {
      return NULL;
    }
    $isPublished = (bool) $newValue;
    if ($isPublished === (bool) $oldValue) {
      return NULL;
    }
    return $isPublished ? 'published' : 'unpublished';
  }

}
