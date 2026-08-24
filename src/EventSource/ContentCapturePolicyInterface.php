<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\Core\Entity\EntityInterface;

/**
 * Controls which content entities may produce changelog events.
 */
interface ContentCapturePolicyInterface {

  public const LEGACY_ENTITY_TYPES = ['node', 'media', 'block_content', 'taxonomy_term'];

  /**
   * Determines whether an entity is enabled by the current capture policy.
   */
  public function allows(EntityInterface $entity): bool;

  /**
   * Determines whether an entity type is enabled.
   */
  public function isEntityTypeEnabled(string $entityTypeId): bool;

  /**
   * Determines whether a bundle is enabled.
   */
  public function isBundleEnabled(string $entityTypeId, string $bundle): bool;

  /**
   * Gets eligible entity types and bundles for configuration interfaces.
   *
   * @return array<string, array{label: string, privacy_sensitive: bool, bundles: array<string, string>}>
   *   Discovery information keyed by entity type ID.
   */
  public function getEligibleEntityTypes(): array;

}
