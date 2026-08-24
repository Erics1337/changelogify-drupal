<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;

/**
 * Shared setup for Changelogify kernel tests.
 */
abstract class ChangelogifyKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'node',
    'media',
    'block_content',
    'taxonomy',
    'options',
    'datetime',
    'changelogify',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['changelogify', 'node']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('changelogify_event');
    $this->installEntitySchema('changelogify_release');
    $this->installSchema('node', ['node_access']);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
  }

  /**
   * Loads stored changelog events in chronological order.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyEventInterface[]
   *   Loaded events.
   */
  protected function loadEvents(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('changelogify_event');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('timestamp', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

}
