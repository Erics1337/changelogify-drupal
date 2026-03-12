<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests config defaults and retention cleanup.
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
class ChangelogifyConfigAndMaintenanceKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests that defaults are installed for the settings config.
   */
  public function testSettingsDefaults(): void {
    $config = $this->config('changelogify.settings');

    $this->assertTrue((bool) $config->get('track_content'));
    $this->assertFalse((bool) $config->get('track_modules'));
    $this->assertFalse((bool) $config->get('track_users'));
    $this->assertSame(0, (int) $config->get('event_retention_days'));
  }

  /**
   * Tests that source settings gate optional module and user events.
   */
  public function testOptionalSourceSettingsAreHonored(): void {
    $hooks = $this->container->get('Drupal\changelogify\Hook\ChangelogifyHooks');

    $hooks->modulesInstalled(['views_ui'], FALSE);
    $this->assertCount(0, $this->loadEvents());

    $this->config('changelogify.settings')
      ->set('track_modules', TRUE)
      ->save();

    $hooks->modulesInstalled(['views_ui'], FALSE);
    $events = $this->loadEvents();
    $this->assertCount(1, $events);
    $this->assertSame('module_installed', $events[0]->getEventType());

    $this->clearEvents();

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getAccountName')->willReturn('editor');

    $hooks->userInsert($user);
    $this->assertCount(0, $this->loadEvents());

    $this->config('changelogify.settings')
      ->set('track_users', TRUE)
      ->save();

    $hooks->userInsert($user);
    $events = $this->loadEvents();
    $this->assertCount(1, $events);
    $this->assertSame('user_created', $events[0]->getEventType());
  }

  /**
   * Tests that cron deletes expired events when retention is enabled.
   */
  public function testCronDeletesExpiredEvents(): void {
    $event_manager = $this->container->get('changelogify.event_manager');
    $now = $this->container->get('datetime.time')->getRequestTime();

    $event_manager->logEvent([
      'timestamp' => $now - (45 * 24 * 60 * 60),
      'event_type' => 'module_installed',
      'source' => 'system',
      'message' => 'Installed module: views_ui',
    ]);
    $event_manager->logEvent([
      'timestamp' => $now - (5 * 24 * 60 * 60),
      'event_type' => 'module_installed',
      'source' => 'system',
      'message' => 'Installed module: media',
    ]);

    $this->config('changelogify.settings')
      ->set('event_retention_days', 30)
      ->save();

    changelogify_cron();

    $events = $this->loadEvents();
    $this->assertCount(1, $events);
    $this->assertSame('Installed module: media', $events[0]->getMessage());
  }

}
