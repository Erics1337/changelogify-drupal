<?php

/**
 * @file
 * Boots PHPUnit for the Changelogify module test suite.
 */

declare(strict_types=1);

use Drupal\Core\DrupalKernel;

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(DrupalKernel::class)) {
  $loader->addPsr4('Drupal\\', dirname(__DIR__) . '/web/core/lib/Drupal');
  $loader->addPsr4('Drupal\\Tests\\', dirname(__DIR__) . '/web/core/tests/Drupal/Tests');
  $loader->addPsr4('Drupal\\KernelTests\\', dirname(__DIR__) . '/web/core/tests/Drupal/KernelTests');
  $loader->addPsr4('Drupal\\FunctionalTests\\', dirname(__DIR__) . '/web/core/tests/Drupal/FunctionalTests');
  $loader->addPsr4('Drupal\\FunctionalJavascriptTests\\', dirname(__DIR__) . '/web/core/tests/Drupal/FunctionalJavascriptTests');
  $loader->addPsr4('Drupal\\BuildTests\\', dirname(__DIR__) . '/web/core/tests/Drupal/BuildTests');
  $loader->addPsr4('Drupal\\TestTools\\', dirname(__DIR__) . '/web/core/tests/Drupal/TestTools');
}

require_once dirname(__DIR__) . '/web/core/lib/Drupal.php';
require_once dirname(__DIR__) . '/web/core/includes/bootstrap.inc';
require_once dirname(__DIR__) . '/web/core/tests/bootstrap.php';
