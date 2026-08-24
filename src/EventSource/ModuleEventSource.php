<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\EventInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Captures module installation and uninstallation events.
 */
final class ModuleEventSource implements EventSourceInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventSourceRecorderInterface $recorder,
    private readonly ConfigInstallerInterface $configInstaller,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'extensions';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Track extension changes';
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivacyDescription(): string {
    return 'Log events when modules or themes are installed or uninstalled outside configuration synchronization.';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigurationDefaults(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedEventTypes(): array {
    return [
      'module_installed',
      'module_uninstalled',
      'theme_installed',
      'theme_uninstalled',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getLegacyEnabledSetting(): ?string {
    return 'track_modules';
  }

  /**
   * Implements hook_modules_installed().
   */
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing) {
      return;
    }
    foreach ($modules as $module) {
      if ($module !== 'changelogify') {
        $this->record(
          $module,
          'module_installed',
          $this->t('Installed module: @module', ['@module' => $module])->__toString(),
          'added',
        );
      }
    }
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  public function modulesUninstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing) {
      return;
    }
    foreach ($modules as $module) {
      $this->record(
        $module,
        'module_uninstalled',
        $this->t('Uninstalled module: @module', ['@module' => $module])->__toString(),
        'removed',
      );
    }
  }

  /**
   * Implements hook_themes_installed().
   */
  public function themesInstalled(array $themes): void {
    if ($this->configInstaller->isSyncing()) {
      return;
    }
    foreach ($themes as $theme) {
      $this->record(
        $theme,
        'theme_installed',
        $this->t('Installed theme: @theme', ['@theme' => $theme])->__toString(),
        'added',
        'theme',
      );
    }
  }

  /**
   * Implements hook_themes_uninstalled().
   */
  public function themesUninstalled(array $themes): void {
    if ($this->configInstaller->isSyncing()) {
      return;
    }
    foreach ($themes as $theme) {
      $this->record(
        $theme,
        'theme_uninstalled',
        $this->t('Uninstalled theme: @theme', ['@theme' => $theme])->__toString(),
        'removed',
        'theme',
      );
    }
  }

  /**
   * Records one module lifecycle event.
   */
  private function record(
    string $extension,
    string $eventType,
    string $message,
    string $section,
    string $extensionType = 'module',
  ): void {
    $this->recorder->record($this, new EventInput(
      eventType: $eventType,
      source: 'extension',
      message: $message,
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      sectionHint: $section,
      metadata: [
        'extension_name' => $extension,
        'extension_type' => $extensionType,
      ],
    ));
  }

}
