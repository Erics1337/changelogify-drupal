<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\EventSource\ContentEventSource;
use Drupal\changelogify\EventSource\ContentCapturePolicyInterface;
use Drupal\changelogify\EventSource\EventSourceRegistryInterface;
use Drupal\changelogify\EventSource\ModuleEventSource;
use Drupal\changelogify\EventSubscriber\ConfigImportSubscriber;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\node\Entity\Node;
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
    self::assertSame(['config_import', 'content', 'modules', 'users'], array_keys($registry->getSources()));
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
    self::assertSame(['create' => 205, 'update' => 2, 'delete' => 1], $metadata['totals']);
    self::assertSame(200, $metadata['member_count']);
    self::assertSame(2, $metadata['excluded_count']);
    self::assertSame(6, $metadata['truncated_count']);
    self::assertSame('view', $metadata['members'][0]['category']);
    self::assertSame('default', $metadata['members'][0]['collection']);
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
    $importer = $this->createMock(ConfigImporter::class);
    $importer->method('getStorageComparer')->willReturn($comparer);
    $importer->method('getErrors')->willReturn($errors);
    return new ConfigImporterEvent($importer);
  }

}
