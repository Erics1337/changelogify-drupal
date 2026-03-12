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
class ChangelogifyHooksKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests media, custom block, and taxonomy term logging.
   */
  public function testSupportedContentEntitiesLogReadableEvents(): void {
    $hooks = $this->container->get('Drupal\changelogify\Hook\ChangelogifyHooks');

    $hooks->entityInsert($this->createEntityDouble('media', 'image', 'Homepage Hero', '/media/homepage-hero', 10));
    $hooks->entityUpdate($this->createEntityDouble('media', 'image', 'Homepage Hero', '/media/homepage-hero', 10));
    $hooks->entityDelete($this->createEntityDouble('media', 'image', 'Homepage Hero', '/media/homepage-hero', 10));

    $hooks->entityInsert($this->createEntityDouble('block_content', 'basic', 'Promo Banner', '/admin/content/block/1', 11));
    $hooks->entityUpdate($this->createEntityDouble('block_content', 'basic', 'Promo Banner', '/admin/content/block/1', 11));
    $hooks->entityDelete($this->createEntityDouble('block_content', 'basic', 'Promo Banner', '/admin/content/block/1', 11));

    $hooks->entityInsert($this->createEntityDouble('taxonomy_term', 'tags', 'Drupal', '/taxonomy/term/1', 12));
    $hooks->entityUpdate($this->createEntityDouble('taxonomy_term', 'tags', 'Drupal', '/taxonomy/term/1', 12));
    $hooks->entityDelete($this->createEntityDouble('taxonomy_term', 'tags', 'Drupal', '/taxonomy/term/1', 12));

    $events = $this->loadEvents();
    $this->assertCount(9, $events);

    $this->assertSame('media_created', $events[0]->getEventType());
    $this->assertSame('Created Image media item: "Homepage Hero"', $events[0]->getMessage());
    $this->assertSame('block_content_updated', $events[4]->getEventType());
    $this->assertSame('Updated Basic block: "Promo Banner"', $events[4]->getMessage());
    $this->assertSame('taxonomy_term_deleted', $events[8]->getEventType());
    $this->assertSame('Deleted Tags term: "Drupal"', $events[8]->getMessage());
  }

  /**
   * Tests node create, update, publish, unpublish, and delete logging.
   */
  public function testNodePublicationStateChangesAreLogged(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'About Us',
      'status' => 0,
    ]);
    $node->save();

    $node->setTitle('About Our Team');
    $node->save();

    $node->setPublished();
    $node->save();

    $node->setUnpublished();
    $node->save();

    $node->delete();

    $events = $this->loadEvents();
    $event_types = array_map(static fn($event): string => $event->getEventType(), $events);
    $messages = array_map(static fn($event): string => $event->getMessage(), $events);

    $this->assertSame([
      'node_created',
      'node_updated',
      'node_published',
      'node_unpublished',
      'node_deleted',
    ], $event_types);

    $this->assertSame([
      'Created Page: "About Us"',
      'Updated Page: "About Our Team"',
      'Published Page: "About Our Team"',
      'Unpublished Page: "About Our Team"',
      'Deleted Page: "About Our Team"',
    ], $messages);
  }

  /**
   * Creates a content entity double for generic hook tests.
   */
  protected function createEntityDouble(string $entity_type_id, string $bundle, string $label, string $path, int $id): EntityInterface {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn($entity_type_id);
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($label);
    $entity->method('toUrl')->willReturn(Url::fromUri('internal:' . $path));
    $entity->method('getEntityType')->willReturn(new ContentEntityType([
      'id' => $entity_type_id,
      'label' => ucfirst(str_replace('_', ' ', $entity_type_id)),
    ]));

    return $entity;
  }

}
