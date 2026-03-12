<?php

declare(strict_types=1);

namespace Drupal\changelogify\Hook;

use Drupal\changelogify\EventManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\UserInterface;

/**
 * Hook implementations for changelogify.
 */
class ChangelogifyHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EventManagerInterface $eventManager,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $this->logContentEvent($entity, 'created', 'added');
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $node_state_change = $this->getNodePublicationAction($entity);
    if ($node_state_change === 'published') {
      $this->logContentEvent($entity, 'published', 'added');
      return;
    }

    if ($node_state_change === 'unpublished') {
      $this->logContentEvent($entity, 'unpublished', 'removed');
      return;
    }

    $this->logContentEvent($entity, 'updated', 'changed');
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $this->logContentEvent($entity, 'deleted', 'removed');
  }

  /**
   * Implements hook_modules_installed().
   */
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || !$this->isTrackingEnabled('track_modules')) {
      return;
    }

    foreach ($modules as $module) {
      // Skip logging our own installation.
      if ($module === 'changelogify') {
        continue;
      }

      $this->eventManager->logEvent([
        'event_type' => 'module_installed',
        'source' => 'system',
        'message' => $this->t('Installed module: @module', ['@module' => $module])->__toString(),
        'section_hint' => 'added',
        'metadata' => [
          'module' => $module,
        ],
      ]);
    }
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  public function modulesUninstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || !$this->isTrackingEnabled('track_modules')) {
      return;
    }

    foreach ($modules as $module) {
      $this->eventManager->logEvent([
        'event_type' => 'module_uninstalled',
        'source' => 'system',
        'message' => $this->t('Uninstalled module: @module', ['@module' => $module])->__toString(),
        'section_hint' => 'removed',
        'metadata' => [
          'module' => $module,
        ],
      ]);
    }
  }

  /**
   * Implements hook_user_insert().
   */
  public function userInsert(UserInterface $account): void {
    if (!$this->isTrackingEnabled('track_users')) {
      return;
    }

    $this->eventManager->logEvent([
      'event_type' => 'user_created',
      'source' => 'user',
      'entity_type_id' => 'user',
      'entity_id' => (int) $account->id(),
      'message' => $this->t('Created user: @name', ['@name' => $account->getAccountName()])->__toString(),
      'section_hint' => 'added',
      'metadata' => [
        'username' => $account->getAccountName(),
      ],
    ]);
  }

  /**
   * Implements hook_user_update().
   */
  public function userUpdate(UserInterface $account): void {
    if (!$this->isTrackingEnabled('track_users')) {
      return;
    }

    $original = $this->getOriginalEntity($account);
    if (!$original instanceof UserInterface) {
      return;
    }

    // Check if roles changed.
    $old_roles = $original->getRoles();
    $new_roles = $account->getRoles();

    if ($old_roles !== $new_roles) {
      $this->eventManager->logEvent([
        'event_type' => 'user_role_changed',
        'source' => 'user',
        'entity_type_id' => 'user',
        'entity_id' => (int) $account->id(),
        'message' => $this->t('Changed roles for user: @name', ['@name' => $account->getAccountName()])->__toString(),
        'section_hint' => 'changed',
        'metadata' => [
          'username' => $account->getAccountName(),
          'old_roles' => $old_roles,
          'new_roles' => $new_roles,
        ],
      ]);
    }
  }

  /**
   * Implements hook_theme().
   */
  public function theme(): array {
    return [
      'changelogify_release_list' => [
        'variables' => [
          'releases' => [],
        ],
      ],
      'changelogify_release' => [
        'variables' => [
          'release' => NULL,
          'sections' => [],
        ],
      ],
    ];
  }

  /**
   * Determines whether content tracking is enabled for the entity.
   */
  protected function shouldTrackContentEntity(EntityInterface $entity): bool {
    return $this->isTrackingEnabled('track_content')
      && in_array($entity->getEntityTypeId(), ['node', 'media', 'block_content', 'taxonomy_term'], TRUE);
  }

  /**
   * Determines whether a specific tracking setting is enabled.
   */
  protected function isTrackingEnabled(string $setting_name): bool {
    return (bool) $this->configFactory->get('changelogify.settings')->get($setting_name);
  }

  /**
   * Detects node publication state changes.
   */
  protected function getNodePublicationAction(EntityInterface $entity): ?string {
    if ($entity->getEntityTypeId() !== 'node' || !$entity instanceof EntityPublishedInterface) {
      return NULL;
    }

    $original = $this->getOriginalEntity($entity);
    if (!$original instanceof EntityPublishedInterface) {
      return NULL;
    }

    $was_published = (bool) $original->isPublished();
    $is_published = (bool) $entity->isPublished();

    if (!$was_published && $is_published) {
      return 'published';
    }

    if ($was_published && !$is_published) {
      return 'unpublished';
    }

    return NULL;
  }

  /**
   * Logs a supported content entity event.
   */
  protected function logContentEvent(EntityInterface $entity, string $action, string $section_hint): void {
    $event_data = [
      'event_type' => $this->buildContentEventType($entity->getEntityTypeId(), $action),
      'source' => 'content_entity',
      'message' => $this->buildContentMessage($entity, $action),
      'section_hint' => $section_hint,
      'metadata' => $this->buildContentMetadata($entity, $action),
    ];

    if ($entity->id() !== NULL) {
      $event_data['entity_id'] = (int) $entity->id();
    }

    $event_data['entity_type_id'] = $entity->getEntityTypeId();

    if ($entity->bundle() !== NULL) {
      $event_data['bundle'] = $entity->bundle();
    }

    $this->eventManager->logEvent($event_data);
  }

  /**
   * Builds a human-readable event message for supported content entities.
   */
  protected function buildContentMessage(EntityInterface $entity, string $action): string {
    $verbs = [
      'created' => $this->t('Created'),
      'updated' => $this->t('Updated'),
      'deleted' => $this->t('Deleted'),
      'published' => $this->t('Published'),
      'unpublished' => $this->t('Unpublished'),
    ];

    return $this->t('@verb @descriptor: "@label"', [
      '@verb' => $verbs[$action] ?? ucfirst($action),
      '@descriptor' => $this->buildEntityDescriptor($entity),
      '@label' => $this->buildEntityLabel($entity),
    ])->__toString();
  }

  /**
   * Builds a stable event type for supported content entities.
   */
  protected function buildContentEventType(string $entity_type_id, string $action): string {
    return $entity_type_id . '_' . $action;
  }

  /**
   * Builds metadata for supported content entities.
   */
  protected function buildContentMetadata(EntityInterface $entity, string $action): array {
    $metadata = [
      'action' => $action,
      'label' => $this->buildEntityLabel($entity),
    ];

    $path = $this->buildEntityPath($entity);
    if ($path !== NULL) {
      $metadata['path'] = $path;
    }

    return $metadata;
  }

  /**
   * Builds a descriptor for supported content entities.
   */
  protected function buildEntityDescriptor(EntityInterface $entity): string {
    $bundle_label = $this->getBundleLabel($entity);

    return match ($entity->getEntityTypeId()) {
      'media' => $this->t('@bundle media item', ['@bundle' => $bundle_label])->__toString(),
      'block_content' => $this->t('@bundle block', ['@bundle' => $bundle_label])->__toString(),
      'taxonomy_term' => $this->t('@bundle term', ['@bundle' => $bundle_label])->__toString(),
      default => $bundle_label,
    };
  }

  /**
   * Gets a friendly label for the entity bundle.
   */
  protected function getBundleLabel(EntityInterface $entity): string {
    $bundle = $entity->bundle();
    if ($bundle !== NULL && $bundle !== '') {
      $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId());
      if (!empty($bundle_info[$bundle]['label'])) {
        return (string) $bundle_info[$bundle]['label'];
      }

      return ucwords(str_replace(['_', '-'], ' ', $bundle));
    }

    return (string) ($entity->getEntityType()->getLabel() ?? ucfirst(str_replace('_', ' ', $entity->getEntityTypeId())));
  }

  /**
   * Gets a friendly entity label for changelog messages.
   */
  protected function buildEntityLabel(EntityInterface $entity): string {
    $label = $entity->label();
    return $label !== NULL && $label !== '' ? $label : $this->t('Untitled')->__toString();
  }

  /**
   * Safely builds a path for supported content entities.
   */
  protected function buildEntityPath(EntityInterface $entity): ?string {
    try {
      return $entity->toUrl()->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Returns the original entity when available across supported core versions.
   */
  protected function getOriginalEntity(EntityInterface $entity): ?EntityInterface {
    if (method_exists($entity, 'getOriginal')) {
      $original = $entity->getOriginal();
      return $original instanceof EntityInterface ? $original : NULL;
    }

    if (isset($entity->original) && $entity->original instanceof EntityInterface) {
      return $entity->original;
    }

    return NULL;
  }

}
