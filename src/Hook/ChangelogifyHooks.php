<?php

declare(strict_types=1);

namespace Drupal\changelogify\Hook;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\EventSource\ContentEventSource;
use Drupal\changelogify\EventSource\ModuleEventSource;
use Drupal\changelogify\EventSource\UserEventSource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\user\UserInterface;

/**
 * Implements non-source Changelogify hooks.
 */
final class ChangelogifyHooks {

  public function __construct(
    private readonly EventManagerInterface $eventManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ContentEventSource $contentSource,
    private readonly ModuleEventSource $moduleSource,
    private readonly UserEventSource $userSource,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->contentSource->entityInsert($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->contentSource->entityUpdate($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->contentSource->entityDelete($entity);
  }

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $isSyncing): void {
    $this->moduleSource->modulesInstalled($modules, $isSyncing);
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  #[Hook('modules_uninstalled')]
  public function modulesUninstalled(array $modules, bool $isSyncing): void {
    $this->moduleSource->modulesUninstalled($modules, $isSyncing);
  }

  /**
   * Implements hook_user_insert().
   */
  #[Hook('user_insert')]
  public function userInsert(UserInterface $account): void {
    $this->userSource->userInsert($account);
  }

  /**
   * Implements hook_user_update().
   */
  #[Hook('user_update')]
  public function userUpdate(UserInterface $account): void {
    $this->userSource->userUpdate($account);
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
    $retentionDays = (int) ($this->configFactory
      ->get('changelogify.settings')
      ->get('event_retention_days') ?? 90);
    $this->eventManager->purgeExpiredEvents($retentionDays);
  }

}
