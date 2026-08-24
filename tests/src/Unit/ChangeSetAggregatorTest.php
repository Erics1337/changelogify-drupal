<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\ChangeSet\ChangeSetAggregator;
use Drupal\changelogify\ChangeSet\ChangeSetGroupingStrategyInterface;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests deterministic change-set aggregation.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class ChangeSetAggregatorTest extends TestCase {

  /**
   * Tests correlation IDs group first regardless of event source details.
   */
  public function testCorrelationGrouping(): void {
    $aggregator = new ChangeSetAggregator([]);
    $result = $aggregator->aggregate([
      $this->event(2, 101, 'config_import_succeeded', correlationId: 'import:1'),
      $this->event(1, 100, 'module_installed', correlationId: 'import:1'),
    ]);

    self::assertCount(1, $result->changeSets);
    self::assertSame('correlated', $result->changeSets[0]->kind);
    self::assertSame([1, 2], $result->changeSets[0]->sourceEventIds);
    self::assertSame('import:1', $result->changeSets[0]->provenance['correlation_id']);
  }

  /**
   * Tests repeated saves collapse without merging different entities.
   */
  public function testConservativeEntityGrouping(): void {
    $aggregator = new ChangeSetAggregator([]);
    $result = $aggregator->aggregate([
      $this->event(1, 100, 'node_updated', entityType: 'node', entityId: 10),
      $this->event(2, 200, 'node_updated', entityType: 'node', entityId: 10),
      $this->event(3, 201, 'node_updated', entityType: 'node', entityId: 11),
      $this->event(4, 600, 'node_updated', entityType: 'node', entityId: 10),
    ]);

    self::assertCount(3, $result->changeSets);
    self::assertSame([1, 2], $result->changeSets[0]->sourceEventIds);
    self::assertSame([3], $result->changeSets[1]->sourceEventIds);
    self::assertSame([4], $result->changeSets[2]->sourceEventIds);
  }

  /**
   * Tests ordering, contributed priority, and deterministic IDs.
   */
  public function testStrategyOrderingAndDeterminism(): void {
    $low = $this->createMock(ChangeSetGroupingStrategyInterface::class);
    $low->method('getPriority')->willReturn(1);
    $low->method('getGroupKey')->willReturn('low');
    $low->method('getKind')->willReturn('low_kind');
    $high = $this->createMock(ChangeSetGroupingStrategyInterface::class);
    $high->method('getPriority')->willReturn(100);
    $high->method('getGroupKey')->willReturn('shared');
    $high->method('getKind')->willReturn('high_kind');
    $aggregator = new ChangeSetAggregator([$low, $high]);
    $first = $aggregator->aggregate([
      $this->event(2, 200, 'example_changed'),
      $this->event(1, 100, 'example_changed'),
    ]);
    $second = $aggregator->aggregate([
      $this->event(1, 100, 'example_changed'),
      $this->event(2, 200, 'example_changed'),
    ]);

    self::assertSame('high_kind', $first->changeSets[0]->kind);
    self::assertSame([1, 2], $first->changeSets[0]->sourceEventIds);
    self::assertSame($first->changeSets[0]->id, $second->changeSets[0]->id);
  }

  /**
   * Tests every input is included or explicitly suppressed once.
   */
  public function testSuppressionAccounting(): void {
    $aggregator = new ChangeSetAggregator([]);
    $result = $aggregator->aggregate([
      $this->event(1, 100, 'node_updated', entityType: 'node', entityId: 10),
      $this->event(2, 100, 'node_published', entityType: 'node', entityId: 10),
      $this->event(3, 101, 'example_changed', message: '   '),
      $this->event(4, 102, 'example_changed'),
    ]);

    self::assertSame([
      1 => 'duplicate_publication_update',
      3 => 'empty_message',
    ], $result->suppressedEvents);
    self::assertSame([2, 4], array_merge(...array_map(
      static fn ($changeSet): array => $changeSet->sourceEventIds,
      $result->changeSets,
    )));
  }

  /**
   * Tests unrelated publication-shaped events cannot suppress updates.
   */
  public function testSuppressionRequiresRelatedEntity(): void {
    $result = (new ChangeSetAggregator([]))->aggregate([
      $this->event(1, 100, 'content_updated'),
      $this->event(2, 100, 'content_published'),
    ]);

    self::assertSame([], $result->suppressedEvents);
    self::assertSame([1, 2], array_merge(...array_map(
      static fn ($changeSet): array => $changeSet->sourceEventIds,
      $result->changeSets,
    )));
  }

  /**
   * Tests provenance snapshots remain bounded for large correlated groups.
   */
  public function testProvenanceSnapshotBound(): void {
    $events = [];
    foreach (range(1, 201) as $id) {
      $events[] = $this->event($id, $id, 'config_changed', correlationId: 'import:large');
    }
    $provenance = (new ChangeSetAggregator([]))
      ->aggregate($events)
      ->changeSets[0]
      ->provenance;

    self::assertSame(201, $provenance['event_count']);
    self::assertCount(200, $provenance['events']);
  }

  /**
   * Tests the aggregation bound matches release loading limits.
   */
  public function testAggregationBound(): void {
    $events = array_fill(0, 5001, $this->event(1, 100, 'example_changed'));
    $this->expectException(\LengthException::class);
    (new ChangeSetAggregator([]))->aggregate($events);
  }

  /**
   * Creates a normalized event double.
   */
  private function event(
    int $id,
    int $timestamp,
    string $eventType,
    string $message = 'Changed something',
    ?string $correlationId = NULL,
    ?string $entityType = NULL,
    ?int $entityId = NULL,
  ): ChangelogifyEventInterface {
    $event = $this->createMock(ChangelogifyEventInterface::class);
    $event->method('id')->willReturn($id);
    $event->method('getTimestamp')->willReturn($timestamp);
    $event->method('getEventType')->willReturn($eventType);
    $event->method('getMessage')->willReturn($message);
    $event->method('getCorrelationId')->willReturn($correlationId);
    $event->method('getRelatedEntityTypeId')->willReturn($entityType);
    $event->method('getRelatedEntityId')->willReturn($entityId);
    $event->method('getSource')->willReturn($entityType === NULL ? 'test' : 'content_entity');
    $event->method('getSectionHint')->willReturn('changed');
    $event->method('getSchemaVersion')->willReturn(1);
    return $event;
  }

}
