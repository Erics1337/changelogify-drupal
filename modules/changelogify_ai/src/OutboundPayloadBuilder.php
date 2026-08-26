<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds the exact, eligible, redacted evidence supplied to prompt templates.
 */
final class OutboundPayloadBuilder {

  public const ELIGIBILITY_CATEGORIES = [
    'content',
    'extensions',
    'users',
    'configuration',
    'custom',
  ];

  private const MAX_MESSAGES = 20;
  private const MAX_DIMENSION_VALUES = 50;
  private const MAX_FIELD_VALUES = 20;
  private const MAX_SCALAR_LENGTH = 512;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds a payload keyed by stable change-set ID.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Candidate change sets. Site-wide eligibility is applied before policy.
   *
   * @return array<string, array<string, mixed>>
   *   Eligible, bounded, and redacted payload data.
   */
  public function build(array $changeSets): array {
    $config = $this->configFactory->get('changelogify_ai.settings');
    $policy = $config->get('policy') ?: [];
    $eligibleCategories = $this->eligibleCategories($config->get('eligibility.categories'));
    $events = $this->loadEvents($changeSets);
    $payload = [];
    foreach ($changeSets as $changeSet) {
      $changeSetEvents = $this->eventsForChangeSet($changeSet->sourceEventIds, $events);
      $sources = $this->sourceValues($changeSet, $changeSetEvents);
      if (!$this->isEligible($sources, $eligibleCategories)) {
        continue;
      }
      $payload[$changeSet->id] = $this->document($changeSet, $changeSetEvents, $sources, $policy);
    }
    return $payload;
  }

  /**
   * Creates a retention-safe payload hash.
   */
  public function hash(array $payload): string {
    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns a version derived from the effective outbound-data policy.
   */
  public function policyVersion(): string {
    $policy = $this->configFactory->get('changelogify_ai.settings')->get('policy') ?: [];
    return hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
  }

  /**
   * Returns a version derived from the effective evidence-eligibility policy.
   */
  public function eligibilityVersion(): string {
    $configured = $this->configFactory
      ->get('changelogify_ai.settings')
      ->get('eligibility.categories');
    return hash('sha256', json_encode($this->eligibleCategories($configured), JSON_THROW_ON_ERROR));
  }

  /**
   * Loads all source events once and indexes valid event entities by ID.
   */
  private function loadEvents(array $changeSets): array {
    $ids = [];
    foreach ($changeSets as $changeSet) {
      foreach ($changeSet->sourceEventIds as $id) {
        $ids[(int) $id] = (int) $id;
      }
    }
    if ($ids === []) {
      return [];
    }
    $loaded = $this->entityTypeManager
      ->getStorage('changelogify_event')
      ->loadMultiple(array_values($ids));
    return array_filter(
      $loaded,
      static fn (object $event): bool => $event instanceof ChangelogifyEventInterface,
    );
  }

  /**
   * Restores deterministic source-event order after one bulk entity load.
   */
  private function eventsForChangeSet(array $ids, array $events): array {
    $selected = [];
    foreach ($ids as $id) {
      if (($events[(int) $id] ?? NULL) instanceof ChangelogifyEventInterface) {
        $selected[] = $events[(int) $id];
      }
    }
    return $selected;
  }

  /**
   * Builds one bounded evidence document after eligibility is established.
   */
  private function document(object $changeSet, array $events, array $sources, array $policy): array {
    $context = $changeSet->summaryContext;
    $messages = [];
    $eventTypes = [];
    $bundles = [];
    $changedFields = [];
    $correlationIds = [];
    $usernames = [];
    $paths = [];
    $unpublishedLabels = [];
    $fieldValues = is_array($context['field_values'] ?? NULL) ? $context['field_values'] : [];
    $metadataUnavailable = FALSE;

    foreach ($events as $event) {
      try {
        $metadata = $event->getMetadata();
      }
      catch (\Throwable) {
        $metadata = [];
        $metadataUnavailable = TRUE;
      }
      $messages[] = $this->redactMessage($event->getMessage(), $metadata, $event->getEventType(), $policy);
      $eventTypes[] = $event->getEventType();
      if ($event->getRelatedBundle() !== NULL) {
        $bundles[] = $event->getRelatedBundle();
      }
      if ($event->getCorrelationId() !== NULL) {
        $correlationIds[] = $event->getCorrelationId();
      }
      $changedFields = array_merge($changedFields, $this->scalarList($metadata['changed_fields'] ?? []));
      $usernames = array_merge($usernames, $this->scalarList($metadata['username'] ?? []));
      $paths = array_merge($paths, $this->scalarList($metadata['path'] ?? []));
      if (($metadata['action'] ?? NULL) === 'unpublished' || str_ends_with($event->getEventType(), '_unpublished')) {
        $unpublishedLabels = array_merge($unpublishedLabels, $this->scalarList($metadata['label'] ?? []));
      }
      $fieldValues = array_replace($fieldValues, $this->allowlistedMetadata($metadata, $policy['allowlisted_values'] ?? []));
    }

    if ($events === []) {
      $messages[] = $this->redactMessage((string) ($context['message'] ?? ''), $context, '', $policy);
      $eventTypes = $this->scalarList($context['event_types'] ?? []);
      $bundles = $this->scalarList($context['bundles'] ?? $context['bundle_label'] ?? []);
      $changedFields = $this->scalarList($context['changed_field_names'] ?? $context['changed_field_name'] ?? []);
      $usernames = $this->scalarList($context['usernames'] ?? $context['username'] ?? []);
      $paths = $this->scalarList($context['paths'] ?? $context['path'] ?? []);
      $unpublishedLabels = $this->scalarList($context['unpublished_labels'] ?? $context['unpublished_label'] ?? []);
      $correlationIds = $this->scalarList($context['correlation_ids'] ?? []);
    }

    $summary = $messages === [] ? '' : end($messages);
    $valueTruncated = count(array_unique($messages)) > self::MAX_MESSAGES
      || count(array_unique($eventTypes)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($sources)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($bundles)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($changedFields)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($correlationIds)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($usernames)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($paths)) > self::MAX_DIMENSION_VALUES
      || count(array_unique($unpublishedLabels)) > self::MAX_DIMENSION_VALUES
      || count($fieldValues) > self::MAX_FIELD_VALUES;
    $messages = $this->boundedList($messages, self::MAX_MESSAGES);
    $eventTypes = $this->boundedList($eventTypes, self::MAX_DIMENSION_VALUES, TRUE);
    $sources = $this->boundedList($sources, self::MAX_DIMENSION_VALUES, TRUE);
    $bundles = $this->boundedList($bundles, self::MAX_DIMENSION_VALUES, TRUE);
    $changedFields = $this->boundedList($changedFields, self::MAX_DIMENSION_VALUES, TRUE);
    $correlationIds = $this->boundedList($correlationIds, self::MAX_DIMENSION_VALUES, TRUE);
    $usernames = $this->boundedList($usernames, self::MAX_DIMENSION_VALUES, TRUE);
    $paths = $this->boundedList($paths, self::MAX_DIMENSION_VALUES, TRUE);
    $unpublishedLabels = $this->boundedList($unpublishedLabels, self::MAX_DIMENSION_VALUES, TRUE);
    $eventCount = (int) ($changeSet->provenance['event_count'] ?? count($changeSet->sourceEventIds));
    $policyExclusions = [];

    $document = [
      'id' => $changeSet->id,
      'kind' => $changeSet->kind,
      'section' => $changeSet->suggestedSection,
      'summary' => $summary,
      'messages' => $messages,
      'event_types' => $eventTypes,
      'event_count' => $eventCount,
      'start_timestamp' => (int) ($changeSet->startTimestamp ?? 0),
      'end_timestamp' => (int) ($changeSet->endTimestamp ?? 0),
      'sources' => $sources,
      'source_event_ids' => $this->redactList(
        $changeSet->sourceEventIds,
        $policy['entity_ids'] ?? 'redact',
        'source_event_ids',
        $policyExclusions,
        TRUE,
      ),
      'bundles' => $this->redactList(
        $bundles,
        $policy['bundle_labels'] ?? 'redact',
        'bundles',
        $policyExclusions,
      ),
      'changed_field_names' => $this->redactList(
        $changedFields,
        $policy['changed_field_names'] ?? 'redact',
        'changed_field_names',
        $policyExclusions,
      ),
      'correlation' => [
        'present' => $correlationIds !== [],
        'ids' => $this->redactList(
          $correlationIds,
          $policy['correlation_ids'] ?? 'redact',
          'correlation_ids',
          $policyExclusions,
        ),
      ],
      'evidence_status' => (string) ($changeSet->provenance['evidence_status'] ?? 'available'),
      'included_event_count' => count($events),
      'truncated' => $metadataUnavailable
      || $valueTruncated
      || $eventCount > count($events)
      || count($changeSet->sourceEventIds) > count($events),
    ];

    $this->addSensitiveContext($document, $context, $policy, $policyExclusions);
    $document['usernames'] = $this->redactList($usernames, $policy['usernames'] ?? 'redact', 'usernames', $policyExclusions);
    $document['paths'] = $this->redactList($paths, $policy['paths'] ?? 'redact', 'paths', $policyExclusions);
    $document['unpublished_labels'] = $this->redactList(
      $unpublishedLabels,
      $policy['unpublished_labels'] ?? 'redact',
      'unpublished_labels',
      $policyExclusions,
    );
    $allowedValues = $this->allowlistedValues($fieldValues, $policy['allowlisted_values'] ?? []);
    if ($allowedValues !== []) {
      $document['field_values'] = $allowedValues;
    }
    if ($metadataUnavailable) {
      $policyExclusions[] = 'unavailable_event_metadata';
    }
    $document['policy_exclusions'] = $this->boundedList($policyExclusions, self::MAX_DIMENSION_VALUES, TRUE);
    return $document;
  }

  /**
   * Retains legacy singular context fields with their existing policy shape.
   */
  private function addSensitiveContext(array &$document, array $context, array $policy, array &$policyExclusions): void {
    foreach (['username', 'actor_id', 'path', 'unpublished_label', 'bundle_label', 'changed_field_name'] as $key) {
      if (!isset($context[$key])) {
        continue;
      }
      $treatment = $policy[$key . 's'] ?? 'redact';
      $document[$key] = $this->apply($context[$key], $treatment);
      if ($treatment !== 'include') {
        $policyExclusions[] = $key;
      }
    }
  }

  /**
   * Resolves event source values with a change-set fallback.
   */
  private function sourceValues(object $changeSet, array $events): array {
    $sources = array_map(
      static fn (ChangelogifyEventInterface $event): string => $event->getSource(),
      $events,
    );
    if ($sources === [] && isset($changeSet->summaryContext['source'])) {
      $sources[] = (string) $changeSet->summaryContext['source'];
    }
    return $this->boundedList($sources, self::MAX_DIMENSION_VALUES, TRUE);
  }

  /**
   * Requires every source represented by a coherent change set to be eligible.
   */
  private function isEligible(array $sources, array $eligibleCategories): bool {
    if ($eligibleCategories === []) {
      return FALSE;
    }
    foreach ($sources === [] ? [''] : $sources as $source) {
      if (!in_array($this->sourceCategory($source), $eligibleCategories, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Maps stable first-party event sources and contributed sources to controls.
   */
  private function sourceCategory(string $source): string {
    return match ($source) {
      'content', 'content_entity' => 'content',
      'extension', 'module', 'theme' => 'extensions',
      'user', 'account' => 'users',
      'config', 'config_import' => 'configuration',
      default => 'custom',
    };
  }

  /**
   * Normalizes configured categories while preserving an explicit empty set.
   */
  private function eligibleCategories(mixed $configured): array {
    if ($configured === NULL) {
      return self::ELIGIBILITY_CATEGORIES;
    }
    if (!is_array($configured)) {
      return [];
    }
    $categories = array_values(array_intersect(self::ELIGIBILITY_CATEGORIES, $configured));
    sort($categories);
    return $categories;
  }

  /**
   * Applies a configured treatment to one scalar value.
   */
  private function apply(mixed $value, string $treatment): string {
    return $treatment === 'include' ? $this->clean((string) $value) : '[redacted]';
  }

  /**
   * Redacts one list and records the excluded evidence category.
   */
  private function redactList(array $values, string $treatment, string $category, array &$exclusions, bool $integers = FALSE): array {
    if ($values === []) {
      return [];
    }
    if ($treatment !== 'include') {
      $exclusions[] = $category;
      return [];
    }
    return $integers
      ? array_values(array_map('intval', $values))
      : $this->boundedList($values, self::MAX_DIMENSION_VALUES, TRUE);
  }

  /**
   * Redacts sensitive metadata values wherever they occur in evidence text.
   */
  private function redactMessage(string $message, array $metadata, string $eventType, array $policy): string {
    $redactions = [];
    if (($policy['usernames'] ?? 'redact') !== 'include') {
      $redactions = array_merge($redactions, $this->scalarList($metadata['username'] ?? []));
    }
    if (($policy['paths'] ?? 'redact') !== 'include') {
      $redactions = array_merge($redactions, $this->scalarList($metadata['path'] ?? []));
    }
    if (($policy['unpublished_labels'] ?? 'redact') !== 'include'
      && (($metadata['action'] ?? NULL) === 'unpublished' || str_ends_with($eventType, '_unpublished'))) {
      $redactions = array_merge($redactions, $this->scalarList($metadata['label'] ?? []));
    }
    usort($redactions, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
    foreach (array_unique($redactions) as $value) {
      if ($value !== '') {
        $message = str_replace($value, '[redacted]', $message);
      }
    }
    return $this->clean($message);
  }

  /**
   * Includes explicitly named, scalar metadata except credential-like keys.
   */
  private function allowlistedMetadata(array $metadata, mixed $allowlist): array {
    if (!is_array($allowlist)) {
      return [];
    }
    $allowed = array_fill_keys(array_map('strval', $allowlist), TRUE);
    $values = [];
    foreach ($metadata as $name => $value) {
      if (is_string($name)
        && isset($allowed[$name])
        && is_scalar($value)
        && !$this->isCredentialKey($name)) {
        $values[$name] = (string) $value;
      }
    }
    return $values;
  }

  /**
   * Includes only explicitly named scalar values from structured context.
   */
  private function allowlistedValues(mixed $values, mixed $allowlist): array {
    if (!is_array($values) || !is_array($allowlist)) {
      return [];
    }
    $names = array_values(array_unique(array_map('strval', $allowlist)));
    sort($names);
    $included = [];
    foreach ($names as $name) {
      if (count($included) >= self::MAX_FIELD_VALUES) {
        break;
      }
      if (isset($values[$name]) && is_scalar($values[$name]) && !$this->isCredentialKey($name)) {
        $included[$name] = $this->clean((string) $values[$name]);
      }
    }
    return $included;
  }

  /**
   * Rejects credentials even when an administrator accidentally allowlists one.
   */
  private function isCredentialKey(string $name): bool {
    return preg_match('/(?:^|_)(?:pass(?:word)?|secret|token|api_?key|credential|authorization|cookie|private_?key)(?:$|_)/i', $name) === 1;
  }

  /**
   * Normalizes a scalar or list into a flat list of strings.
   */
  private function scalarList(mixed $values): array {
    $values = is_array($values) ? $values : [$values];
    return array_values(array_map(
      fn (mixed $value): string => $this->clean(is_scalar($value) ? (string) $value : ''),
      array_filter($values, 'is_scalar'),
    ));
  }

  /**
   * Cleans, deduplicates, optionally sorts, and bounds a list.
   */
  private function boundedList(array $values, int $maximum, bool $sort = FALSE): array {
    $bounded = [];
    foreach ($values as $value) {
      $value = $this->clean((string) $value);
      if ($value !== '') {
        $bounded[$value] = $value;
      }
    }
    $bounded = array_values($bounded);
    if ($sort) {
      sort($bounded);
    }
    return array_slice($bounded, 0, $maximum);
  }

  /**
   * Removes markup and control characters and bounds one scalar value.
   */
  private function clean(string $value): string {
    $withoutExecutableContent = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@is', '', $value) ?? '';
    $stripped = strip_tags($withoutExecutableContent);
    $cleaned = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $stripped) ?? $stripped);
    return mb_check_encoding($cleaned, 'UTF-8')
      ? mb_substr($cleaned, 0, self::MAX_SCALAR_LENGTH)
      : substr($cleaned, 0, self::MAX_SCALAR_LENGTH);
  }

}
