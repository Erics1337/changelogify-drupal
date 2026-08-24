<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\EventSource\ContentEventSource;
use Drupal\changelogify\EventSource\ContentCapturePolicyInterface;
use Drupal\changelogify\EventSource\EventSourceRegistryInterface;
use Drupal\changelogify\EventSource\ModuleEventSource;
use Drupal\changelogify\EventSource\EventSourceRecorderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\changelogify\EventSubscriber\ConfigImportSubscriber;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests first-party source discovery and configuration.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class EventSourceKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests first-party sources are available to settings and diagnostics.
   */
  public function testFirstPartySourceDiscovery(): void {
    $registry = $this->container->get(EventSourceRegistryInterface::class);
    self::assertSame(['config_import', 'content', 'extensions', 'users'], array_keys($registry->getSources()));
    self::assertContains('node_created', $registry->getSource('content')->getSupportedEventTypes());
    self::assertFalse($registry->getSource('users')->getConfigurationDefaults()['enabled']);
  }

  /**
   * Tests disabling a source stops capture but preserves stored events.
   */
  public function testDisabledSourcePreservesHistoricalEvents(): void {
    $source = $this->container->get(ContentEventSource::class);
    $first = Node::create(['type' => 'page', 'title' => 'First', 'status' => TRUE]);
    $source->entityInsert($first);
    self::assertCount(1, $this->loadEvents());

    $this->config('changelogify.settings')
      ->set('event_sources.content.enabled', FALSE)
      ->save();
    $second = Node::create(['type' => 'page', 'title' => 'Second', 'status' => TRUE]);
    $source->entityInsert($second);

    $events = $this->loadEvents();
    self::assertCount(1, $events);
    self::assertSame('Created Page: "First"', $events[0]->getMessage());
  }

  /**
   * Tests newly discovered entity types require explicit opt-in.
   */
  public function testNewEntityTypesAreDisabledByDefault(): void {
    $policy = $this->container->get(ContentCapturePolicyInterface::class);
    self::assertArrayHasKey('file', $policy->getEligibleEntityTypes());
    self::assertFalse($policy->isEntityTypeEnabled('file'));
    self::assertFalse($policy->isBundleEnabled('file', 'file'));
  }

  /**
   * Tests bundle exclusion applies to every content lifecycle operation.
   */
  public function testBundleExclusionAppliesToAllOperations(): void {
    $this->config('changelogify.settings')
      ->set('track_unpublished_content', TRUE)
      ->set('content_capture.entity_types.node.bundles.page', FALSE)
      ->save();
    $source = $this->container->get(ContentEventSource::class);
    $node = Node::create(['type' => 'page', 'title' => 'Excluded', 'status' => TRUE]);

    $source->entityInsert($node);
    $source->entityUpdate($node);
    $node->setUnpublished();
    $source->entityUpdate($node);
    $node->setPublished();
    $source->entityUpdate($node);
    $source->entityDelete($node);

    self::assertSame([], $this->loadEvents());
  }

  /**
   * Tests stale imported policy entries are ignored safely.
   */
  public function testStalePolicyConfigurationIsSafe(): void {
    $this->config('changelogify.settings')
      ->set('content_capture.entity_types.removed_type', [
        'enabled' => TRUE,
        'default_bundle_enabled' => TRUE,
        'bundles' => ['removed_bundle' => TRUE],
      ])
      ->set('content_capture.entity_types.node.bundles.removed_bundle', TRUE)
      ->save();
    $policy = $this->container->get(ContentCapturePolicyInterface::class);

    self::assertFalse($policy->isEntityTypeEnabled('removed_type'));
    self::assertFalse($policy->isBundleEnabled('node', 'removed_bundle'));
    self::assertTrue($policy->isBundleEnabled('node', 'page'));
  }

  /**
   * Tests successful imports are correlated, classified, bounded, and filtered.
   */
  public function testSuccessfulConfigImportOperation(): void {
    $this->config('changelogify.settings')
      ->set('config_import.excluded_patterns', ['system.*'])
      ->save();
    $createNames = array_map(
      static fn (int $index): string => "views.view.imported_$index",
      range(1, 205),
    );
    $event = $this->configImporterEvent([
      StorageInterface::DEFAULT_COLLECTION => [
        'create' => $createNames,
        'update' => ['user.role.editor'],
        'delete' => ['system.site'],
        'rename' => ['views.view.old::views.view.new'],
      ],
      'language.fr' => [
        'create' => [],
        'update' => ['example.translation_settings'],
        'delete' => [],
      ],
    ]);

    // Synchronization-specific module hooks remain suppressed.
    $this->container->get(ModuleEventSource::class)
      ->modulesInstalled(['example'], TRUE);
    $subscriber = $this->container->get(ConfigImportSubscriber::class);
    $subscriber->onImport($event);
    $subscriber->onImport($event);

    $events = $this->loadEvents();
    self::assertCount(1, $events);
    self::assertSame('config_import_succeeded', $events[0]->getEventType());
    self::assertNotNull($events[0]->getCorrelationId());
    $metadata = $events[0]->getMetadata();
    self::assertSame(['create' => 205, 'update' => 2, 'delete' => 1, 'rename' => 1], $metadata['totals']);
    self::assertSame(200, $metadata['member_count']);
    self::assertSame(2, $metadata['excluded_count']);
    self::assertSame('view', $metadata['members'][0]['category']);
    self::assertSame('default', $metadata['members'][0]['collection']);
    self::assertSame(7, $metadata['truncated_count']);
  }

  /**
   * Tests rename-only imports retain both safe technical names.
   */
  public function testConfigRenameOperation(): void {
    $event = $this->configImporterEvent([
      StorageInterface::DEFAULT_COLLECTION => [
        'rename' => ['views.view.old::views.view.new'],
      ],
    ]);
    $this->container->get(ConfigImportSubscriber::class)->onImport($event);

    $metadata = $this->loadEvents()[0]->getMetadata();
    self::assertSame(1, $metadata['totals']['rename']);
    self::assertSame('views.view.old', $metadata['members'][0]['name']);
    self::assertSame('views.view.new', $metadata['members'][0]['new_name']);
  }

  /**
   * Tests failed imports never appear as successful changes.
   */
  public function testFailedConfigImportOperation(): void {
    $event = $this->configImporterEvent([], ['Validation failed.']);
    $subscriber = $this->container->get(ConfigImportSubscriber::class);
    $subscriber->onValidate($event);
    $subscriber->onImport($event);

    $events = $this->loadEvents();
    self::assertCount(1, $events);
    self::assertSame('config_import_failed', $events[0]->getEventType());
    self::assertSame('failed', $events[0]->getMetadata()['status']);
    self::assertSame('Configuration import failed.', $events[0]->getMessage());
  }

  /**
   * Tests direct extension events and synchronized duplicate suppression.
   */
  public function testExtensionLifecycleSemantics(): void {
    $source = $this->container->get(ModuleEventSource::class);
    $source->modulesInstalled(['changelogify', 'example'], FALSE);
    $source->modulesUninstalled(['example'], FALSE);
    $source->themesInstalled(['stark']);
    $source->themesUninstalled(['stark']);

    $syncingInstaller = $this->createMock(ConfigInstallerInterface::class);
    $syncingInstaller->method('isSyncing')->willReturn(TRUE);
    $syncingSource = new ModuleEventSource(
      $this->container->get(EventSourceRecorderInterface::class),
      $syncingInstaller,
      $this->container->get(TimeInterface::class),
      $this->container->get(AccountProxyInterface::class),
    );
    $source->modulesInstalled(['synchronized_module'], TRUE);
    $syncingSource->themesInstalled(['synchronized_theme']);

    $events = $this->loadEvents();
    self::assertSame([
      'module_installed',
      'module_uninstalled',
      'theme_installed',
      'theme_uninstalled',
    ], array_map(static fn ($event): string => $event->getEventType(), $events));
    foreach ($events as $event) {
      self::assertSame('extension', $event->getSource());
    }
    self::assertSame('example', $events[0]->getMetadata()['extension_name']);
    self::assertSame('theme', $events[2]->getMetadata()['extension_type']);
  }

  /**
   * Tests role definitions and account assignments use distinct semantics.
   */
  public function testRolePermissionAndAssignmentSemantics(): void {
    $this->config('changelogify.settings')
      ->set('track_users', TRUE)
      ->set('config_import.include_sensitive', TRUE)
      ->save();
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    $account = User::create([
      'name' => 'editor_account',
      'mail' => 'editor@example.com',
      'status' => TRUE,
    ]);
    $account->save();
    $account->addRole('editor')->save();

    $event = $this->configImporterEvent([
      StorageInterface::DEFAULT_COLLECTION => [
        'create' => [],
        'update' => ['user.role.editor'],
        'delete' => [],
      ],
    ]);
    $this->container->get(ConfigImportSubscriber::class)->onImport($event);

    $events = $this->loadEvents();
    $assignmentEvents = array_values(array_filter(
      $events,
      static fn ($storedEvent): bool => $storedEvent->getEventType() === 'user_role_assignments_changed',
    ));
    self::assertCount(1, $assignmentEvents);
    self::assertStringContainsString('role assignments', $assignmentEvents[0]->getMessage());
    $configEvent = end($events);
    self::assertSame('config_import_succeeded', $configEvent->getEventType());
    self::assertSame('role', $configEvent->getMetadata()['members'][0]['category']);
    self::assertNotSame($assignmentEvents[0]->getCorrelationId(), $configEvent->getCorrelationId());
  }

  /**
   * Creates a configuration importer event double from operation membership.
   */
  private function configImporterEvent(array $changes, array $errors = []): ConfigImporterEvent {
    $comparer = $this->createMock(StorageComparerInterface::class);
    $comparer->method('getAllCollectionNames')->willReturn(
      $changes === [] ? [StorageInterface::DEFAULT_COLLECTION] : array_keys($changes),
    );
    $comparer->method('getChangelist')->willReturnCallback(
      static fn (?string $operation, string $collection): array => $changes[$collection][$operation] ?? [],
    );
    $comparer->method('extractRenameNames')->willReturnCallback(
      static function (string $name): array {
        [$oldName, $newName] = explode('::', $name, 2);
        return ['old_name' => $oldName, 'new_name' => $newName];
      },
    );
    $importer = $this->createMock(ConfigImporter::class);
    $importer->method('getStorageComparer')->willReturn($comparer);
    $importer->method('getErrors')->willReturn($errors);
    return new ConfigImporterEvent($importer);
  }

}
