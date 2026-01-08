<?php

/**
 * @file
 * Custom bootstrap for Changelogify tests.
 */

// Load core bootstrap (required for UnitTestCase and other core utilities).
require __DIR__ . '/../../../../core/tests/bootstrap.php';

// Register module namespace.
$loader = require __DIR__ . '/../../../../../vendor/autoload.php';

// Tests are running from web/modules/custom/..., but source is at repo root.
// We need to register the module's src directory manually.
$src_path = realpath(__DIR__ . '/../../../../../src');
if (!$src_path) {
    die("Could not find src path at " . __DIR__ . '/../../../../../src');
}
$loader->addPsr4('Drupal\changelogify\\', $src_path);

// Force include interfaces to workaround autoloading quirks for this specific test runner.
require_once $src_path . '/Entity/ChangelogifyEventInterface.php';
require_once $src_path . '/EventManagerInterface.php';
require_once $src_path . '/ReleaseGenerator.php';
