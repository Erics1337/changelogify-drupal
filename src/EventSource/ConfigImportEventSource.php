<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

/**
 * Declares configuration-import operation events.
 */
final class ConfigImportEventSource implements EventSourceInterface {

  /**
   * {@inheritdoc} */
  public function getId(): string {
    return 'config_import';
  }

  /**
   * {@inheritdoc} */
  public function getLabel(): string {
    return 'Track configuration imports';
  }

  /**
   * {@inheritdoc} */
  public function getPrivacyDescription(): string {
    return 'Stores configuration object names and classifications, not exported YAML values. Sensitive categories are excluded by default.';
  }

  /**
   * {@inheritdoc} */
  public function getConfigurationDefaults(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc} */
  public function getSupportedEventTypes(): array {
    return ['config_import_succeeded', 'config_import_failed'];
  }

  /**
   * {@inheritdoc} */
  public function getLegacyEnabledSetting(): ?string {
    return NULL;
  }

}
