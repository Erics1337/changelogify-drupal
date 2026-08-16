<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests release event normalization.
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseNormalizationKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests redundant updates and duplicate messages are consolidated.
   */
  public function testReleaseEventsAreNormalizedWithProvenance(): void {
    $eventManager = $this->container->get('changelogify.event_manager');
    $releaseGenerator = $this->container->get('changelogify.release_generator');

    $eventManager->logEvent([
      'timestamp' => 1500,
      'event_type' => 'node_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'node',
      'entity_id' => 1,
      'message' => 'Updated Page: "About Us"',
      'section_hint' => 'changed',
    ]);
    $eventManager->logEvent([
      'timestamp' => 1500,
      'event_type' => 'node_published',
      'source' => 'content_entity',
      'entity_type_id' => 'node',
      'entity_id' => 1,
      'message' => 'Published Page: "About Us"',
      'section_hint' => 'added',
    ]);
    $firstDuplicate = $eventManager->logEvent([
      'timestamp' => 1600,
      'event_type' => 'media_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'media',
      'entity_id' => 2,
      'message' => 'Updated Image media item: "Homepage Hero"',
      'section_hint' => 'changed',
    ]);
    $secondDuplicate = $eventManager->logEvent([
      'timestamp' => 1601,
      'event_type' => 'media_updated',
      'source' => 'content_entity',
      'entity_type_id' => 'media',
      'entity_id' => 2,
      'message' => 'Updated Image media item: "Homepage Hero"',
      'section_hint' => 'changed',
    ]);

    $release = $releaseGenerator->generateReleaseFromRange(
      new \DateTimeImmutable('@1000'),
      new \DateTimeImmutable('@2000'),
      ['title' => 'March Release'],
    );
    $sections = $release->getSections();

    self::assertCount(1, $sections['added']);
    self::assertSame('Published Page: "About Us"', $sections['added'][0]['text']);
    self::assertCount(1, $sections['changed']);
    self::assertSame([
      (int) $firstDuplicate->id(),
      (int) $secondDuplicate->id(),
    ], $sections['changed'][0]['event_ids']);
  }

}
