<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Functional;

use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests installation and deterministic uninstall cleanup.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class LifecycleFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['changelogify'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests uninstall removes module-owned data and active configuration.
   */
  public function testUninstallRemovesModuleData(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $event = $entityTypeManager->getStorage('changelogify_event')->create([
      'timestamp' => 1_700_000_000,
      'event_type' => 'content_created',
      'source' => 'content_entity',
      'message' => 'Data intentionally removed during uninstall.',
    ]);
    $event->save();
    $release = $entityTypeManager->getStorage('changelogify_release')->create([
      'title' => 'Release intentionally removed during uninstall',
      'release_date' => 1_700_000_001,
      'status' => FALSE,
      'uid' => 0,
    ]);
    $release->save();

    self::assertNotEmpty($this->config('changelogify.settings')->getRawData());
    $schema = $this->container->get('database')->schema();
    self::assertTrue($schema->tableExists('changelogify_event'));
    self::assertTrue($schema->tableExists('changelogify_release'));

    try {
      $this->container->get('module_installer')->uninstall(['changelogify']);
      self::fail('Uninstall must be blocked while Changelogify content exists.');
    }
    catch (ModuleUninstallValidatorException $exception) {
      self::assertStringContainsString('Remove events', $exception->getMessage());
      self::assertStringContainsString('Remove releases', $exception->getMessage());
    }

    // A blocked attempt preserves the module, configuration, and all data.
    self::assertTrue($this->container->get('module_handler')->moduleExists('changelogify'));
    self::assertNotNull($entityTypeManager->getStorage('changelogify_event')->load($event->id()));
    self::assertNotNull($entityTypeManager->getStorage('changelogify_release')->load($release->id()));

    // Deleting a release is itself tracked, so remove releases first and then
    // clear the complete event storage just before uninstall.
    $entityTypeManager->getStorage('changelogify_release')->delete([$release]);
    $eventStorage = $entityTypeManager->getStorage('changelogify_event');
    $eventStorage->delete($eventStorage->loadMultiple());
    $this->container->get('module_installer')->uninstall(['changelogify']);

    self::assertFalse($this->container->get('module_handler')->moduleExists('changelogify'));
    self::assertSame([], $this->config('changelogify.settings')->getRawData());
    self::assertFalse($schema->tableExists('changelogify_event'));
    self::assertFalse($schema->tableExists('changelogify_release'));
  }

}
