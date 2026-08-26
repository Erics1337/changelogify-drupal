<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\changelogify\ChangeSet\ChangeSet;

/**
 * Applies validated one-time exclusions to policy-eligible evidence.
 */
final class SynthesisEvidenceSelector {

  public function __construct(
    private readonly OutboundPayloadBuilder $payloadBuilder,
  ) {}

  /**
   * Builds the exact synthesis boundary shown to an editor and then submitted.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Current date-range evidence.
   * @param array{categories?: array, sources?: array, evidence?: array} $exclusions
   *   One-time exclusions submitted by an authorized editor.
   */
  public function select(array $changeSets, array $exclusions = []): array {
    $eligible = $this->payloadBuilder->build($changeSets);
    $categories = [];
    $sources = [];
    $sourceCategories = [];
    foreach ($eligible as $sourceId => $document) {
      $documentSources = $this->stringList($document['sources'] ?? []);
      $category = $this->category($documentSources, (string) ($document['kind'] ?? ''));
      $sourceCategories[$sourceId] = $category;
      $categories[$category] = $category;
      foreach ($documentSources as $source) {
        $sources[$source] = $source;
      }
    }
    ksort($categories);
    ksort($sources);
    $normalized = [
      'categories' => $this->validated($exclusions['categories'] ?? [], array_keys($categories), 'category'),
      'sources' => $this->validated($exclusions['sources'] ?? [], array_keys($sources), 'source'),
      'evidence' => $this->validated($exclusions['evidence'] ?? [], array_keys($eligible), 'evidence'),
    ];
    $excluded = [];
    foreach ($eligible as $sourceId => $document) {
      if (in_array($sourceId, $normalized['evidence'], TRUE)
        || in_array($sourceCategories[$sourceId], $normalized['categories'], TRUE)
        || array_intersect($this->stringList($document['sources'] ?? []), $normalized['sources']) !== []) {
        $excluded[] = $sourceId;
      }
    }
    $evidence = array_diff_key($eligible, array_flip($excluded));
    $allIds = array_map(
      static fn (ChangeSet $changeSet): string => $changeSet->id,
      $changeSets,
    );
    $ineligible = array_values(array_diff($allIds, array_keys($eligible)));
    $provenance = [];
    foreach ($changeSets as $changeSet) {
      if (!isset($evidence[$changeSet->id])) {
        continue;
      }
      $provenance[$changeSet->id] = [
        'event_ids' => $changeSet->sourceEventIds,
        'event_count' => (int) ($changeSet->provenance['event_count'] ?? count($changeSet->sourceEventIds)),
        'evidence_status' => (string) ($changeSet->provenance['evidence_status'] ?? 'available'),
        'events' => is_array($changeSet->provenance['events'] ?? NULL) ? $changeSet->provenance['events'] : [],
      ];
    }
    $fingerprint = hash('sha256', json_encode([
      'evidence' => $evidence,
      'exclusions' => $normalized,
      'policy_version' => $this->payloadBuilder->policyVersion(),
      'eligibility_version' => $this->payloadBuilder->eligibilityVersion(),
    ], JSON_THROW_ON_ERROR));
    return [
      'evidence' => $evidence,
      'provenance' => $provenance,
      'exclusions' => $normalized,
      'excluded_editor_ids' => $excluded,
      'ineligible_ids' => $ineligible,
      'available_categories' => array_keys($categories),
      'available_sources' => array_keys($sources),
      'fingerprint' => $fingerprint,
      'policy_version' => $this->payloadBuilder->policyVersion(),
      'eligibility_version' => $this->payloadBuilder->eligibilityVersion(),
    ];
  }

  /**
   * Rejects unknown or non-string exclusion values.
   */
  private function validated(mixed $values, array $allowed, string $type): array {
    $values = $this->stringList($values);
    if (array_diff($values, $allowed) !== []) {
      throw new \UnexpectedValueException("The synthesis {$type} exclusions are stale or invalid.");
    }
    sort($values);
    return $values;
  }

  /**
   * Normalizes Drupal checkbox values and submitted scalar lists.
   */
  private function stringList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $values = array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== '0');
    return array_values(array_unique($values));
  }

  /**
   * Maps evidence sources to the site-wide eligibility taxonomy.
   */
  private function category(array $sources, string $kind): string {
    $value = strtolower(implode(' ', $sources) . ' ' . $kind);
    return match (TRUE) {
      str_contains($value, 'config') => 'configuration',
      str_contains($value, 'module'), str_contains($value, 'theme'), str_contains($value, 'extension') => 'extensions',
      str_contains($value, 'user'), str_contains($value, 'account') => 'users',
      str_contains($value, 'content'), str_contains($value, 'entity') => 'content',
      default => 'custom',
    };
  }

}
