<?php

declare(strict_types=1);

namespace Drupal\changelogify\Hook;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\EventInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Session\AccountProxyInterface;
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
    protected TimeInterface $time,
    protected AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $this->logContentEvent($entity, 'created', 'added');
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $publicationAction = $this->getPublicationAction($entity);
    if ($publicationAction === 'published') {
      $this->logContentEvent($entity, 'published', 'added');
      return;
    }
    if ($publicationAction === 'unpublished') {
      $this->logContentEvent($entity, 'unpublished', 'removed');
      return;
    }

    $this->logContentEvent($entity, 'updated', 'changed');
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if (!$this->shouldTrackContentEntity($entity)) {
      return;
    }

    $this->logContentEvent($entity, 'deleted', 'removed');
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || !$this->settingEnabled('track_modules', TRUE)) {
      return;
    }

    foreach ($modules as $module) {
      // Skip logging our own installation.
      if ($module === 'changelogify') {
        continue;
      }

      $this->eventManager->logEventInput(new EventInput(
        eventType: 'module_installed',
        source: 'system',
        message: $this->t('Installed module: @module', ['@module' => $module])->__toString(),
        timestamp: $this->time->getRequestTime(),
        actorId: (int) $this->currentUser->id(),
        sectionHint: 'added',
        metadata: [
          'module' => $module,
        ],
      ));
    }
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  #[Hook('modules_uninstalled')]
  public function modulesUninstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || !$this->settingEnabled('track_modules', TRUE)) {
      return;
    }

    foreach ($modules as $module) {
      $this->eventManager->logEventInput(new EventInput(
        eventType: 'module_uninstalled',
        source: 'system',
        message: $this->t('Uninstalled module: @module', ['@module' => $module])->__toString(),
        timestamp: $this->time->getRequestTime(),
        actorId: (int) $this->currentUser->id(),
        sectionHint: 'removed',
        metadata: [
          'module' => $module,
        ],
      ));
    }
  }

  /**
   * Implements hook_user_insert().
   */
  #[Hook('user_insert')]
  public function userInsert(UserInterface $account): void {
    if (!$this->settingEnabled('track_users', FALSE)) {
      return;
    }

    $this->eventManager->logEventInput(new EventInput(
      eventType: 'user_created',
      source: 'user',
      message: $this->t('Created user: @name', ['@name' => $account->getAccountName()])->__toString(),
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      entityTypeId: 'user',
      entityId: (int) $account->id(),
      sectionHint: 'added',
      metadata: [
        'username' => $account->getAccountName(),
      ],
    ));
  }

  /**
   * Implements hook_user_update().
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $account): void {
    if (!$this->settingEnabled('track_users', FALSE)) {
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
      $this->eventManager->logEventInput(new EventInput(
        eventType: 'user_role_changed',
        source: 'user',
        message: $this->t('Changed roles for user: @name', ['@name' => $account->getAccountName()])->__toString(),
        timestamp: $this->time->getRequestTime(),
        actorId: (int) $this->currentUser->id(),
        entityTypeId: 'user',
        entityId: (int) $account->id(),
        sectionHint: 'changed',
        metadata: [
          'username' => $account->getAccountName(),
          'old_roles' => $old_roles,
          'new_roles' => $new_roles,
        ],
      ));
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'changelogify_release_list' => [
        'variables' => [
          'releases' => [],
          'pager' => NULL,
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
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $retention_days = (int) ($this->configFactory
      ->get('changelogify.settings')
      ->get('event_retention_days') ?? 90);
    $this->eventManager->purgeExpiredEvents($retention_days);
  }

  /**
   * Determines whether a supported content entity should be recorded.
   */
  private function shouldTrackContentEntity(EntityInterface $entity): bool {
    if (!$this->settingEnabled('track_content', TRUE)) {
      return FALSE;
    }

    if (!in_array($entity->getEntityTypeId(), ['node', 'media', 'block_content', 'taxonomy_term'], TRUE)) {
      return FALSE;
    }

    return !$entity instanceof EntityPublishedInterface
      || $entity->isPublished()
      || $this->settingEnabled('track_unpublished_content', FALSE);
  }

  /**
   * Detects publication state changes for publishable content entities.
   */
  private function getPublicationAction(EntityInterface $entity): ?string {
    if (!$entity instanceof EntityPublishedInterface) {
      return NULL;
    }

    $original = $this->getOriginalEntity($entity);
    if (!$original instanceof EntityPublishedInterface) {
      return NULL;
    }

    if (!$original->isPublished() && $entity->isPublished()) {
      return 'published';
    }
    if ($original->isPublished() && !$entity->isPublished()) {
      return 'unpublished';
    }

    return NULL;
  }

  /**
   * Logs a supported content entity event.
   */
  private function logContentEvent(EntityInterface $entity, string $action, string $sectionHint): void {
    $this->eventManager->logEventInput(new EventInput(
      eventType: $entity->getEntityTypeId() . '_' . $action,
      source: 'content_entity',
      message: $this->buildContentMessage($entity, $action),
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      entityTypeId: $entity->getEntityTypeId(),
      entityId: $entity->id() === NULL ? NULL : (int) $entity->id(),
      bundle: $entity->bundle() ?: NULL,
      sectionHint: $sectionHint,
      metadata: $this->buildContentMetadata($entity, $action),
    ));
  }

  /**
   * Builds a readable content event message.
   */
  private function buildContentMessage(EntityInterface $entity, string $action): string {
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
   * Builds metadata for a content event.
   */
  private function buildContentMetadata(EntityInterface $entity, string $action): array {
    $metadata = [
      'action' => $action,
      'label' => $this->buildEntityLabel($entity),
    ];

    try {
      $metadata['path'] = $entity->toUrl()->toString();
    }
    catch (\Throwable) {
      // Some new or deleted entities do not have a routable URL.
    }

    return $metadata;
  }

  /**
   * Builds a human-friendly entity descriptor.
   */
  private function buildEntityDescriptor(EntityInterface $entity): string {
    $bundleLabel = $this->getBundleLabel($entity);

    return match ($entity->getEntityTypeId()) {
      'media' => $this->t('@bundle media item', ['@bundle' => $bundleLabel])->__toString(),
      'block_content' => $this->t('@bundle block', ['@bundle' => $bundleLabel])->__toString(),
      'taxonomy_term' => $this->t('@bundle term', ['@bundle' => $bundleLabel])->__toString(),
      default => $bundleLabel,
    };
  }

  /**
   * Gets a friendly bundle label.
   */
  private function getBundleLabel(EntityInterface $entity): string {
    $bundle = $entity->bundle();
    if ($bundle !== NULL && $bundle !== '') {
      $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entity->getEntityTypeId());
      if (!empty($bundleInfo[$bundle]['label'])) {
        return (string) $bundleInfo[$bundle]['label'];
      }

      return ucwords(str_replace(['_', '-'], ' ', $bundle));
    }

    return (string) ($entity->getEntityType()->getLabel()
      ?? ucfirst(str_replace('_', ' ', $entity->getEntityTypeId())));
  }

  /**
   * Gets a stable display label for an entity.
   */
  private function buildEntityLabel(EntityInterface $entity): string {
    $label = $entity->label();
    return $label !== NULL && $label !== ''
      ? $label
      : $this->t('Untitled')->__toString();
  }

  /**
   * Returns the original entity across supported Drupal versions.
   */
  private function getOriginalEntity(EntityInterface $entity): ?EntityInterface {
    if (method_exists($entity, 'getOriginal')) {
      $original = $entity->getOriginal();
      return $original instanceof EntityInterface ? $original : NULL;
    }

    if (isset($entity->original) && $entity->original instanceof EntityInterface) {
      return $entity->original;
    }

    return NULL;
  }

  /**
   * Reads a boolean setting while preserving a default for existing sites.
   */
  private function settingEnabled(string $key, bool $default): bool {
    $value = $this->configFactory->get('changelogify.settings')->get($key);
    return $value === NULL ? $default : (bool) $value;
  }

}
