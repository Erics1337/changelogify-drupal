<?php

declare(strict_types=1);

namespace Drupal\changelogify\Hook;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\EventSource\ContentEventSource;
use Drupal\changelogify\EventSource\ModuleEventSource;
use Drupal\changelogify\EventSource\UserEventSource;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;
use Drupal\changelogify\ScheduledPublicationManager;
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
    private readonly ReleaseProvenanceManagerInterface $provenanceManager,
    private readonly ScheduledPublicationManager $scheduledPublicationManager,
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
   * Implements hook_themes_installed().
   */
  #[Hook('themes_installed')]
  public function themesInstalled(array $themes): void {
    $this->moduleSource->themesInstalled($themes);
  }

  /**
   * Implements hook_themes_uninstalled().
   */
  #[Hook('themes_uninstalled')]
  public function themesUninstalled(array $themes): void {
    $this->moduleSource->themesUninstalled($themes);
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
          'title' => '',
          'date' => '',
          'date_iso' => '',
          'version' => NULL,
          'sections' => [],
          'translation_fallback' => FALSE,
          'language_name' => '',
        ],
      ],
      'changelogify_release_block' => [
        'variables' => [
          'releases' => [],
          'show_date' => TRUE,
          'show_version' => TRUE,
          'changelog_url' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->scheduledPublicationManager->processDue();
    $settings = $this->configFactory->get('changelogify.settings');
    $retentionDays = (int) ($settings
      ->get('event_retention_days') ?? 90);
    $this->eventManager->purgeExpiredEvents($retentionDays);
    $provenanceRetentionDays = (int) ($settings
      ->get('provenance_retention_days') ?? 0);
    $this->provenanceManager->purgeExpiredProvenance($provenanceRetentionDays);
  }

}
