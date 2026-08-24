<?php

declare(strict_types=1);

namespace Drupal\changelogify\ConfigClassifier;

/**
 * Classifies Drupal configuration into stable technical categories.
 */
interface ConfigClassifierInterface {

  /**
   * Classifies a configuration name in a configuration collection.
   */
  public function classify(string $configName, string $collection = 'default'): ConfigClassification;

}
