<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\EventSource\ContentEventSource;
use Drupal\changelogify\EventSource\ContentCapturePolicyInterface;
use Drupal\changelogify\EventSource\EventSourceRegistryInterface;
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
    self::assertSame(['content', 'modules', 'users'], array_keys($registry->getSources()));
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
    self::assertArrayHasKey('path_alias', $policy->getEligibleEntityTypes());
    self::assertFalse($policy->isEntityTypeEnabled('path_alias'));
    self::assertFalse($policy->isBundleEnabled('path_alias', 'path_alias'));
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

}
