<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests release generation normalization.
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
class ReleaseGeneratorKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests duplicate suppression and publish preference during generation.
   */
  public function testGenerateReleaseNormalizesEvents(): void {
    $event_manager = $this->container->get('changelogify.event_manager');
    $release_generator = $this->container->get('changelogify.release_generator');

    $event_manager->logEvent([
      'timestamp' => 1500,
      'event_type' => 'node_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'node',
      'entity_id' => 1,
      'bundle' => 'page',
      'message' => 'Updated Page: "About Us"',
      'section_hint' => 'changed',
    ]);
    $event_manager->logEvent([
      'timestamp' => 1501,
      'event_type' => 'node_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'node',
      'entity_id' => 1,
      'bundle' => 'page',
      'message' => 'Updated Page: "About Us"',
      'section_hint' => 'changed',
    ]);
    $event_manager->logEvent([
      'timestamp' => 1500,
      'event_type' => 'node_published',
      'source' => 'content_entity',
      'entity_type_id' => 'node',
      'entity_id' => 1,
      'bundle' => 'page',
      'message' => 'Published Page: "About Us"',
      'section_hint' => 'added',
    ]);
    $event_manager->logEvent([
      'timestamp' => 1600,
      'event_type' => 'media_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'media',
      'entity_id' => 2,
      'bundle' => 'image',
      'message' => 'Updated Image media item: "Homepage Hero"',
      'section_hint' => 'changed',
    ]);

    $release = $release_generator->generateReleaseFromRange(
      new \DateTimeImmutable('@1000'),
      new \DateTimeImmutable('@2000'),
      ['title' => 'March Release']
    );

    $sections = $release->getSections();

    $this->assertFalse($release->isPublished());
    $this->assertSame('Published Page: "About Us"', $sections['added'][0]['text']);
    $this->assertCount(1, $sections['added']);
    $this->assertCount(1, $sections['changed']);
    $this->assertSame('Updated Image media item: "Homepage Hero"', $sections['changed'][0]['text']);
  }

}
