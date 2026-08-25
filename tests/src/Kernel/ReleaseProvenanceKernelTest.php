<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests release provenance across raw and provenance retention.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleaseProvenanceKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests safe snapshots and distinct evidence lifecycle states.
   */
  public function testProvenanceLifecycle(): void {
    /** @var \Drupal\changelogify\EventManagerInterface $eventManager */
    $eventManager = $this->container->get(EventManagerInterface::class);
    $event = $eventManager->logEvent([
      'timestamp' => 1,
      'event_type' => 'content_updated',
      'source' => 'content_entity',
      'message' => 'Sensitive draft label',
      'entity_type_id' => 'node',
      'entity_id' => '42',
      'metadata' => ['path' => '/private-page', 'secret' => 'do-not-copy'],
    ]);
    $eventId = (int) $event->id();

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Retained release',
      'release_date' => 1,
      'status' => TRUE,
    ]);
    $release->setSections([
      'changed' => [[
        'id' => 'retained-item',
        'text' => 'Public release text',
        'event_ids' => [$eventId],
      ],
      ],
    ])->setProvenance([
      'version' => 1,
      'items' => [
        'retained-item' => [
          'change_set_id' => 'retained-item',
          'event_ids' => [$eventId],
          'evidence_status' => 'available',
          'events' => [[
            'event_id' => $eventId,
            'event_type' => 'content_updated',
            'source' => 'content_entity',
            'timestamp' => 1,
            'entity_type_id' => 'node',
            'entity_id' => '42',
            'evidence_status' => 'available',
          ],
          ],
        ],
      ],
    ])->save();

    $stored = json_encode($release->getProvenance(), JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('Sensitive draft label', $stored);
    self::assertStringNotContainsString('/private-page', $stored);
    self::assertStringNotContainsString('do-not-copy', $stored);

    self::assertSame(1, $eventManager->purgeExpiredEvents(1));
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertNotNull($release);
    self::assertSame('Public release text', $release->getSections()['changed'][0]['text']);
    self::assertSame('expired', $release->getProvenance()['items']['retained-item']['evidence_status']);

    /** @var \Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface $manager */
    $manager = $this->container->get(ReleaseProvenanceManagerInterface::class);
    self::assertSame(1, $manager->purgeExpiredProvenance(1));
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertNotNull($release);
    self::assertSame('removed', $release->getProvenance()['items']['retained-item']['evidence_status']);
    self::assertSame(0, $manager->purgeExpiredProvenance(1));
  }

  /**
   * Tests unresolved legacy references are backfilled as missing.
   */
  public function testMissingEvidenceBackfill(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create([
      'title' => 'Legacy release',
      'release_date' => 1,
      'status' => FALSE,
    ]);
    $release->setSections([
      'changed' => [
        [
          'id' => 'legacy-item',
          'text' => 'Second legacy text',
          'event_ids' => [],
        ],
      ],
      'other' => [[
        'id' => 'legacy-item',
        'text' => 'Legacy text',
        'event_ids' => [999999, 'not-an-id'],
      ],
      ],
    ])->save();

    /** @var \Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface $manager */
    $manager = $this->container->get(ReleaseProvenanceManagerInterface::class);
    self::assertSame(1, $manager->backfillExistingReleases());
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertNotNull($release);
    $provenance = $release->getProvenance();
    self::assertSame('removed', $provenance['items']['legacy-item']['evidence_status']);
    $item = $provenance['items']['legacy-item:2'];
    self::assertSame('partial', $item['evidence_status']);
    self::assertSame('missing', $item['events'][0]['evidence_status']);
    self::assertSame('invalid', $item['events'][1]['evidence_status']);
    self::assertSame('legacy-item', $item['change_set_id']);
  }

  /**
   * Tests truncated evidence remains partial when an unstored event expires.
   */
  public function testTruncatedEvidenceExpiryRemainsPartial(): void {
    $events = [];
    foreach (range(1, 200) as $eventId) {
      $events[] = [
        'event_id' => $eventId,
        'evidence_status' => 'available',
      ];
    }
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create(['title' => 'Truncated evidence']);
    $release->setProvenance([
      'version' => 1,
      'items' => [
        'large-item' => [
          'event_ids' => range(1, 201),
          'event_count' => 201,
          'evidence_status' => 'partial',
          'events' => $events,
        ],
      ],
    ])->save();

    /** @var \Drupal\changelogify\Provenance\ReleaseProvenanceManagerInterface $manager */
    $manager = $this->container->get(ReleaseProvenanceManagerInterface::class);
    $manager->markEventsExpired([201]);
    $storage->resetCache([(int) $release->id()]);
    $release = $storage->load($release->id());
    self::assertNotNull($release);
    self::assertSame('partial', $release->getProvenance()['items']['large-item']['evidence_status']);
  }

  /**
   * Tests prohibited payload fields cannot enter provenance storage.
   */
  public function testProvenanceRejectsPayloadData(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create(['title' => 'Unsafe evidence']);

    $this->expectException(\InvalidArgumentException::class);
    $release->setProvenance([
      'version' => 1,
      'items' => [
        'unsafe' => [
          'event_ids' => [1],
          'evidence_status' => 'available',
          'events' => [
            [
              'event_id' => 1,
              'evidence_status' => 'available',
              'metadata' => ['secret' => 'must-not-persist'],
            ],
          ],
        ],
      ],
    ]);
  }

  /**
   * Tests AI items can retain references to multiple safe change sets.
   */
  public function testProvenanceAcceptsMultipleChangeSetReferences(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    /** @var \Drupal\changelogify\Entity\ChangelogifyReleaseInterface $release */
    $release = $storage->create(['title' => 'Combined evidence']);
    $release->setProvenance([
      'version' => 1,
      'items' => [
        'combined-item' => [
          'change_set_ids' => ['change-1', 'change-2'],
          'kind' => 'ai_combined',
          'section' => 'changed',
          'event_ids' => [],
          'event_count' => 0,
          'evidence_status' => 'available',
          'events' => [],
        ],
      ],
    ])->save();

    self::assertSame(
      ['change-1', 'change-2'],
      $release->getProvenance()['items']['combined-item']['change_set_ids'],
    );
  }

}
