<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Applies privacy-first entity-type and bundle capture configuration.
 */
final class ContentCapturePolicy implements ContentCapturePolicyInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function allows(EntityInterface $entity): bool {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    return $this->isEntityTypeEnabled($entityTypeId)
      && ($bundle === NULL || $bundle === '' || $this->isBundleEnabled($entityTypeId, $bundle));
  }

  /**
   * {@inheritdoc}
   */
  public function isEntityTypeEnabled(string $entityTypeId): bool {
    if (!isset($this->getEligibleEntityTypes()[$entityTypeId])) {
      return FALSE;
    }
    $configured = $this->configFactory->get('changelogify.settings')
      ->get("content_capture.entity_types.$entityTypeId.enabled");
    return $configured === NULL ? FALSE : (bool) $configured;
  }

  /**
   * {@inheritdoc}
   */
  public function isBundleEnabled(string $entityTypeId, string $bundle): bool {
    $eligible = $this->getEligibleEntityTypes();
    if (!isset($eligible[$entityTypeId]['bundles'][$bundle])) {
      return FALSE;
    }
    $config = $this->configFactory->get('changelogify.settings');
    $configured = $config->get("content_capture.entity_types.$entityTypeId.bundles.$bundle");
    if ($configured !== NULL) {
      return (bool) $configured;
    }
    $default = $config->get("content_capture.entity_types.$entityTypeId.default_bundle_enabled");
    return $default === NULL ? FALSE : (bool) $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getEligibleEntityTypes(): array {
    $eligible = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface
        || str_starts_with($id, 'changelogify_')
        || $id === 'user') {
        continue;
      }
      $bundles = [];
      foreach ($this->bundleInfo->getBundleInfo($id) as $bundleId => $info) {
        $bundles[$bundleId] = (string) ($info['label'] ?? $bundleId);
      }
      if ($bundles === []) {
        $bundles[$id] = (string) $definition->getLabel();
      }
      asort($bundles);
      $eligible[$id] = [
        'label' => (string) $definition->getCollectionLabel(),
        'privacy_sensitive' => in_array($id, ['user', 'profile', 'contact_message'], TRUE),
        'bundles' => $bundles,
      ];
    }
    ksort($eligible);
    return $eligible;
  }

}
