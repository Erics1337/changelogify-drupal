<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\EventSource\ContentEventSource;
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

}
