<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;
use Drupal\changelogify\EventManagerInterface;
use Drupal\changelogify\ReleaseGenerator;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the ReleaseGenerator service.
 *
 * @group changelogify
 * @coversDefaultClass \Drupal\changelogify\ReleaseGenerator
 */
class ReleaseGeneratorTest extends UnitTestCase
{

    /**
     * The release generator under test.
     *
     * @var \Drupal\changelogify\ReleaseGenerator
     */
    protected $releaseGenerator;

    /**
     * The entity type manager mock.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityTypeManager;

    /**
     * The event manager mock.
     *
     * @var \Drupal\changelogify\EventManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $eventManager;

    /**
     * The current user mock.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $currentUser;

    /**
     * The time service mock.
     *
     * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $time;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->currentUser = $this->createMock(AccountProxyInterface::class);
        $this->time = $this->createMock(TimeInterface::class);

        $this->releaseGenerator = new ReleaseGenerator(
            $this->entityTypeManager,
            $this->eventManager,
            $this->currentUser,
            $this->time
        );
    }

    /**
     * Tests generating a release from a date range.
     *
     * @covers ::generateReleaseFromRange
     */
    public function testGenerateReleaseFromRange(): void
    {
        $start = new \DateTimeImmutable('2023-01-01');
        $end = new \DateTimeImmutable('2023-01-31');

        // Mock Event.
        $event = $this->createMock(ChangelogifyEventInterface::class);
        $event->method('getSectionHint')->willReturn('added');
        $event->method('uuid')->willReturn('event-uuid-123');
        $event->method('getMessage')->willReturn('Test event message');
        $event->method('id')->willReturn(1);

        // Mock Event Manager returning events.
        $this->eventManager->expects($this->once())
            ->method('getEventsByRange')
            ->with($start, $end)
            ->willReturn([$event]);

        // Mock Release Storage.
        $storage = $this->createMock(EntityStorageInterface::class);
        $this->entityTypeManager->expects($this->any())
            ->method('getStorage')
            ->with('changelogify_release')
            ->willReturn($storage);

        // Mock Release Entity.
        $release = $this->createMock(ChangelogifyReleaseInterface::class);
        $storage->expects($this->once())
            ->method('create')
            ->willReturn($release);

        // Expect setSections to be called with grouped events.
        $release->expects($this->once())
            ->method('setSections')
            ->with($this->callback(function ($sections) {
                return isset($sections['added'])
                    && count($sections['added']) === 1
                    && $sections['added'][0]['text'] === 'Test event message';
            }));

        // Expect save to be called.
        $release->expects($this->once())->method('save');

        // Execute.
        $result = $this->releaseGenerator->generateReleaseFromRange($start, $end, ['title' => 'Test Release']);

        $this->assertSame($release, $result);
    }

}
