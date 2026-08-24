<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

use Drupal\changelogify\EventInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Captures module installation and uninstallation events.
 */
final class ModuleEventSource implements EventSourceInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EventSourceRecorderInterface $recorder,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'modules';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Track module changes';
  }

  /**
   * {@inheritdoc}
   */
  public function getPrivacyDescription(): string {
    return 'Log events when modules are installed or uninstalled.';
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
    return ['module_installed', 'module_uninstalled'];
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
   * Records one module lifecycle event.
   */
  private function record(string $module, string $eventType, string $message, string $section): void {
    $this->recorder->record($this, new EventInput(
      eventType: $eventType,
      source: 'system',
      message: $message,
      timestamp: $this->time->getRequestTime(),
      actorId: (int) $this->currentUser->id(),
      sectionHint: $section,
      metadata: ['module' => $module],
    ));
  }

}
