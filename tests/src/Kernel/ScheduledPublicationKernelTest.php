<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\ScheduledPublicationManager;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests revision-safe scheduled release publication.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ScheduledPublicationKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests due, missed, stale, future, concurrent, and repeated processing.
   */
  public function testScheduledPublicationProcessing(): void {
    $manager = $this->container->get(ScheduledPublicationManager::class);
    self::assertInstanceOf(ScheduledPublicationManager::class, $manager);
    $now = $this->container->get('datetime.time')->getCurrentTime();

    $due = $this->scheduledRelease('Due release', $now - 300);
    $future = $this->scheduledRelease('Future release', $now + 3600);
    $result = $manager->processDue();
    self::assertSame(['published' => 1, 'stale' => 0], $result);
    $due = $this->reload($due);
    self::assertSame('published', $due->getEditorialState());
    self::assertSame(0, $due->getScheduledPublicationTime());
    self::assertSame(['published' => 0, 'stale' => 0], $manager->processDue());
    self::assertSame('review', $this->reload($future)->getEditorialState());

    $stale = $this->scheduledRelease('Stale release', $now - 60);
    $stale->setTitle('Changed after approval')->save();
    $result = $manager->processDue();
    self::assertSame(['published' => 0, 'stale' => 1], $result);
    $stale = $this->reload($stale);
    self::assertSame('review', $stale->getEditorialState());
    self::assertSame(0, $stale->getScheduledPublicationTime());

    $locked = $this->scheduledRelease('Locked release', $now - 30);
    $lock = new DatabaseLockBackend($this->container->get('database'));
    self::assertInstanceOf(LockBackendInterface::class, $lock);
    $lockName = 'changelogify.release_schedule.' . $locked->id();
    self::assertTrue($lock->acquire($lockName, 30.0));
    $concurrentManager = new ScheduledPublicationManager(
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
      new DatabaseLockBackend($this->container->get('database')),
      $this->container->get('logger.factory'),
    );
    self::assertSame(['published' => 0, 'stale' => 0], $concurrentManager->processDue());
    self::assertSame('review', $this->reload($locked)->getEditorialState());
    $lock->release($lockName);
    self::assertSame(['published' => 1, 'stale' => 0], $manager->processDue());
    self::assertSame('published', $this->reload($locked)->getEditorialState());
  }

  /**
   * Creates a reviewed release scheduled against its reviewed revision.
   */
  private function scheduledRelease(string $title, int $timestamp): object {
    $release = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->create([
        'title' => $title,
        'release_date' => 1_700_000_000,
        'editorial_state' => 'review',
      ]);
    $release->setSections([
      'changed' => [[
        'id' => strtolower(str_replace(' ', '-', $title)),
        'text' => 'Approved content.',
        'event_ids' => [],
      ],
      ],
    ])->save();
    $approvedRevisionId = (int) $release->getRevisionId();
    $release->setPublicationSchedule($timestamp, $approvedRevisionId);
    $release->setRevisionLogMessage('Scheduled by test.')->save();
    return $release;
  }

  /**
   * Reloads a release without a stale static cache entry.
   */
  private function reload(object $release): object {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    return $storage->load($release->id());
  }

}
