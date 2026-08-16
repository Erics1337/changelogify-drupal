<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests supported content event logging.
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ContentTrackingKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests media, custom block, and taxonomy term event descriptions.
   */
  public function testGenericContentEntitiesAreTracked(): void {
    $hooks = $this->container->get('Drupal\changelogify\Hook\ChangelogifyHooks');

    $hooks->entityInsert($this->createEntityDouble('media', 'image', 'Homepage Hero', '/media/hero', 10));
    $hooks->entityUpdate($this->createEntityDouble('block_content', 'basic', 'Promo Banner', '/block/1', 11));
    $hooks->entityDelete($this->createEntityDouble('taxonomy_term', 'tags', 'Drupal', '/taxonomy/term/1', 12));

    $events = $this->loadEvents();
    self::assertCount(3, $events);
    self::assertSame('media_created', $events[0]->getEventType());
    self::assertSame('Created Image media item: "Homepage Hero"', $events[0]->getMessage());
    self::assertSame('block_content_updated', $events[1]->getEventType());
    self::assertSame('taxonomy_term_deleted', $events[2]->getEventType());
  }

  /**
   * Tests node publication changes when private tracking is enabled.
   */
  public function testNodePublicationChangesAreTracked(): void {
    $this->config('changelogify.settings')
      ->set('track_unpublished_content', TRUE)
      ->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => FALSE,
    ]);
    $node->save();
    $node->setTitle('About Our Team')->save();
    $node->setPublished()->save();
    $node->setUnpublished()->save();
    $node->delete();

    self::assertSame([
      'node_created',
      'node_updated',
      'node_published',
      'node_unpublished',
      'node_deleted',
    ], array_map(
      static fn ($event): string => $event->getEventType(),
      $this->loadEvents(),
    ));
  }

  /**
   * Creates a content entity double for generic hook tests.
   */
  private function createEntityDouble(string $entityTypeId, string $bundle, string $label, string $path, int $id): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entityTypeId);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($label);
    $entity->method('toUrl')->willReturn(Url::fromUri('internal:' . $path));
    $entity->method('getEntityType')->willReturn(new ContentEntityType([
      'id' => $entityTypeId,
      'label' => ucfirst(str_replace('_', ' ', $entityTypeId)),
    ]));

    return $entity;
  }

}
