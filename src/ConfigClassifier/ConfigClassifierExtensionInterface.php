<?php

declare(strict_types=1);

namespace Drupal\changelogify\ConfigClassifier;

/**
 * Extends configuration classification for contributed configuration.
 */
interface ConfigClassifierExtensionInterface {

  /**
   * Gets priority; higher classifiers run first.
   */
  public function getPriority(): int;

  /**
   * Determines whether this classifier handles a name and collection.
   */
  public function supports(string $configName, string $collection): bool;

  /**
   * Classifies a supported configuration object.
   */
  public function classify(string $configName, string $collection): ConfigClassification;

}
