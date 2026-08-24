<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update hooks for sites upgrading from earlier releases.
 *
 * @group changelogify
 * @runTestsInSeparateProcesses
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class UpdatePathKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests missing settings are backfilled without replacing saved values.
   */
  public function testSettingsBackfill(): void {
    $config = $this->config('changelogify.settings');
    $config
      ->clear('track_content')
      ->set('track_modules', TRUE)
      ->clear('track_users')
      ->clear('event_retention_days')
      ->clear('track_unpublished_content')
      ->set('changelog_path', '/updates')
      ->save();

    $modulePath = $this->container
      ->get('extension.list.module')
      ->getPath('changelogify');
    require_once DRUPAL_ROOT . '/' . $modulePath . '/changelogify.install';

    changelogify_update_12001();
    changelogify_update_13001();

    $config = $this->config('changelogify.settings');
    self::assertTrue($config->get('track_content'));
    self::assertTrue($config->get('track_modules'));
    self::assertFalse($config->get('track_users'));
    self::assertSame(0, $config->get('event_retention_days'));
    self::assertFalse($config->get('track_unpublished_content'));
    self::assertSame('/updates', $config->get('changelog_path'));
  }

}
