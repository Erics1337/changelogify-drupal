<?php

declare(strict_types=1);

namespace Drupal\changelogify\EventSource;

/**
 * Describes a source capable of producing normalized changelog events.
 */
interface EventSourceInterface {

  /**
   * Gets the unique lowercase machine ID.
   */
  public function getId(): string;

  /**
   * Gets the administrator-facing source label.
   */
  public function getLabel(): string;

  /**
   * Describes the data and privacy implications of enabling the source.
   */
  public function getPrivacyDescription(): string;

  /**
   * Gets configuration defaults for this source.
   *
   * @return array{enabled: bool}
   *   The source defaults.
   */
  public function getConfigurationDefaults(): array;

  /**
   * Gets event type IDs that this source may produce.
   *
   * @return string[]
   *   Supported normalized event type IDs.
   */
  public function getSupportedEventTypes(): array;

  /**
   * Gets the pre-1.4 enabled setting key, when one exists.
   */
  public function getLegacyEnabledSetting(): ?string;

}
