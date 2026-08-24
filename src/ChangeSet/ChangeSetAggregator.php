<?php

declare(strict_types=1);

namespace Drupal\changelogify\ChangeSet;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;

/**
 * Applies correlation, contributed, and conservative entity grouping rules.
 */
final class ChangeSetAggregator implements ChangeSetAggregatorInterface {

  private const MAX_EVENTS = 5000;
  private const ENTITY_UPDATE_WINDOW = 300;

  /**
   * Sorted contributed grouping strategies.
   *
   * @var \Drupal\changelogify\ChangeSet\ChangeSetGroupingStrategyInterface[]|null
   */
  private ?array $sortedStrategies = NULL;

  /**
   * Constructs the aggregator.
   *
   * @param iterable<\Drupal\changelogify\ChangeSet\ChangeSetGroupingStrategyInterface> $strategies
   *   Tagged grouping strategies.
   */
  public function __construct(
    private readonly iterable $strategies,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function aggregate(array $events): AggregationResult {
    if (count($events) > self::MAX_EVENTS) {
      throw new \LengthException('Change-set aggregation is limited to 5000 events.');
    }
    usort($events, static fn (
      ChangelogifyEventInterface $left,
      ChangelogifyEventInterface $right,
    ): int => [$left->getTimestamp(), (int) $left->id()]
      <=> [$right->getTimestamp(), (int) $right->id()]);

    [$events, $suppressed] = $this->suppressEvents($events);
    $groups = [];
    foreach ($events as $event) {
      [$key, $kind] = $this->groupIdentity($event, $groups);
      $groups[$key]['kind'] = $kind;
      $groups[$key]['events'][] = $event;
    }

    $changeSets = [];
    foreach ($groups as $group) {
      $changeSets[] = $this->buildChangeSet($group['kind'], $group['events']);
    }
    return new AggregationResult($changeSets, $suppressed);
  }

  /**
   * Suppresses only explicitly explainable legacy noise.
   */
  private function suppressEvents(array $events): array {
    $publicationKeys = [];
    foreach ($events as $event) {
      if (str_ends_with($event->getEventType(), '_published')
        || str_ends_with($event->getEventType(), '_unpublished')) {
        $publicationKeys[$this->entityTimestampKey($event)] = TRUE;
      }
    }

    $included = [];
    $suppressed = [];
    foreach ($events as $event) {
      $id = (int) $event->id();
      if (trim($event->getMessage()) === '') {
        $suppressed[$id] = 'empty_message';
      }
      elseif ((str_ends_with($event->getEventType(), '_updated')
          || $event->getEventType() === 'content_updated')
        && isset($publicationKeys[$this->entityTimestampKey($event)])) {
        $suppressed[$id] = 'duplicate_publication_update';
      }
      else {
        $included[] = $event;
      }
    }
    ksort($suppressed);
    return [$included, $suppressed];
  }

  /**
   * Gets a grouping key and kind in deterministic precedence order.
   */
  private function groupIdentity(ChangelogifyEventInterface $event, array $groups): array {
    $correlationId = $event->getCorrelationId();
    if ($correlationId !== NULL) {
      return ['correlation:' . $correlationId, 'correlated'];
    }
    foreach ($this->getStrategies() as $strategy) {
      $key = $strategy->getGroupKey($event);
      if ($key !== NULL) {
        return ['strategy:' . get_class($strategy) . ':' . $key, $strategy->getKind()];
      }
    }

    if ($this->isEntityUpdate($event)) {
      $prefix = implode(':', [
        'entity',
        $event->getSource(),
        $event->getRelatedEntityTypeId(),
        $event->getRelatedEntityId(),
        $event->getSectionHint() ?? 'other',
      ]);
      $candidate = NULL;
      foreach (array_reverse($groups, TRUE) as $key => $group) {
        if (!str_starts_with($key, $prefix . ':')) {
          continue;
        }
        $last = end($group['events']);
        if ($event->getTimestamp() - $last->getTimestamp() <= self::ENTITY_UPDATE_WINDOW) {
          $candidate = $key;
        }
        break;
      }
      if ($candidate !== NULL) {
        return [$candidate, 'entity_updates'];
      }
      return [$prefix . ':' . $event->getTimestamp(), 'entity_updates'];
    }
    return ['event:' . (int) $event->id(), 'event'];
  }

  /**
   * Builds a value object from one ordered event group.
   */
  private function buildChangeSet(string $kind, array $events): ChangeSet {
    $first = reset($events);
    $last = end($events);
    $eventIds = array_map(static fn (ChangelogifyEventInterface $event): int => (int) $event->id(), $events);
    $eventTypes = array_values(array_unique(array_map(
      static fn (ChangelogifyEventInterface $event): string => $event->getEventType(),
      $events,
    )));
    $correlationId = $first->getCorrelationId();
    $idSeed = $kind . ':' . ($correlationId ?? implode(',', $eventIds));
    return new ChangeSet(
      id: 'changeset-' . substr(hash('sha256', $idSeed), 0, 24),
      kind: $kind,
      startTimestamp: $first->getTimestamp(),
      endTimestamp: $last->getTimestamp(),
      sourceEventIds: $eventIds,
      suggestedSection: $first->getSectionHint() ?? 'other',
      summaryContext: [
        'message' => $last->getMessage(),
        'event_types' => $eventTypes,
        'source' => $first->getSource(),
        'entity_type_id' => $first->getRelatedEntityTypeId(),
        'entity_id' => $first->getRelatedEntityId(),
      ],
      provenance: [
        'correlation_id' => $correlationId,
        'schema_versions' => array_values(array_unique(array_map(
          static fn (ChangelogifyEventInterface $event): int => $event->getSchemaVersion(),
          $events,
        ))),
      ],
    );
  }

  /**
   * Determines whether conservative entity-update grouping applies.
   */
  private function isEntityUpdate(ChangelogifyEventInterface $event): bool {
    return $event->getRelatedEntityTypeId() !== NULL
      && $event->getRelatedEntityId() !== NULL
      && (str_ends_with($event->getEventType(), '_updated')
        || $event->getEventType() === 'content_updated');
  }

  /**
   * Builds the legacy same-entity and timestamp suppression key.
   */
  private function entityTimestampKey(ChangelogifyEventInterface $event): string {
    return implode(':', [
      (string) $event->getRelatedEntityTypeId(),
      (string) $event->getRelatedEntityId(),
      (string) $event->getTimestamp(),
    ]);
  }

  /**
   * Gets contributed strategies in deterministic override order.
   */
  private function getStrategies(): array {
    if ($this->sortedStrategies !== NULL) {
      return $this->sortedStrategies;
    }
    $strategies = is_array($this->strategies)
      ? $this->strategies
      : iterator_to_array($this->strategies, FALSE);
    usort($strategies, static fn (
      ChangeSetGroupingStrategyInterface $left,
      ChangeSetGroupingStrategyInterface $right,
    ): int => $right->getPriority() <=> $left->getPriority()
      ?: get_class($left) <=> get_class($right));
    return $this->sortedStrategies = $strategies;
  }

}
