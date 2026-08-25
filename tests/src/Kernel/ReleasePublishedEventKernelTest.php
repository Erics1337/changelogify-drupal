<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Kernel;

use Drupal\changelogify\Event\ReleasePublishedEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stable release publication domain event.
 *
 * @group changelogify
 */
#[Group('changelogify')]
#[RunTestsInSeparateProcesses]
final class ReleasePublishedEventKernelTest extends ChangelogifyKernelTestBase {

  /**
   * Tests transitions, repetition, unpublication, payload, and isolation.
   */
  public function testPublicationEventLifecycle(): void {
    $events = [];
    $this->container->get('event_dispatcher')->addListener(
      ReleasePublishedEvent::NAME,
      static function (ReleasePublishedEvent $event) use (&$events): void {
        $events[] = $event;
      },
    );
    $release = $this->release('Event release');
    self::assertSame([], $events);

    $release->setEditorialState('published')->save();
    self::assertCount(1, $events);
    $event = $events[0];
    self::assertSame($release->uuid(), $event->releaseUuid);
    self::assertSame((int) $release->getRevisionId(), $event->revisionId);
    self::assertStringContainsString('/changelog/' . $release->getSlug(), $event->canonicalUrl);
    self::assertSame('en', $event->language);
    self::assertGreaterThan(0, $event->publishedAt);
    self::assertSame(
      sprintf('changelogify:publication:%s:%d', $release->uuid(), $release->getRevisionId()),
      $event->idempotencyId,
    );
    self::assertSame($event->idempotencyId, $events[0]->idempotencyId);
    self::assertSame([
      'releaseUuid',
      'canonicalUrl',
      'revisionId',
      'language',
      'publishedAt',
      'idempotencyId',
    ], array_keys(get_object_vars($event)));

    $release->setTitle('Published edit')->save();
    self::assertCount(1, $events);
    $release->setEditorialState('archived')->save();
    self::assertCount(1, $events);
    $release->setEditorialState('draft')->save();
    self::assertCount(1, $events);
    $release->setEditorialState('published')->save();
    self::assertCount(2, $events);
    self::assertNotSame($events[0]->idempotencyId, $events[1]->idempotencyId);
  }

  /**
   * Tests subscriber exceptions cannot corrupt the successful release save.
   */
  public function testFailingSubscriberIsIsolated(): void {
    $this->container->get('event_dispatcher')->addListener(
      ReleasePublishedEvent::NAME,
      static fn (): never => throw new \RuntimeException('Remote notification failed.'),
      100,
    );
    $release = $this->release('Failure isolation release');
    $release->setEditorialState('published')->save();

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release');
    $storage->resetCache([(int) $release->id()]);
    $stored = $storage->load($release->id());
    self::assertNotNull($stored);
    self::assertTrue($stored->isPublished());
    self::assertSame('published', $stored->getEditorialState());
  }

  /**
   * Creates one private draft release.
   */
  private function release(string $title): object {
    $release = $this->container->get('entity_type.manager')
      ->getStorage('changelogify_release')
      ->create([
        'title' => $title,
        'release_date' => 1_700_000_000,
        'editorial_state' => 'draft',
      ]);
    $release->save();
    return $release;
  }

}
