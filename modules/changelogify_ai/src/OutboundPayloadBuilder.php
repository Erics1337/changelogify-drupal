<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds the exact, redacted evidence payload supplied to prompt templates.
 */
final class OutboundPayloadBuilder {

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Builds a payload keyed by stable change-set ID.
   *
   * @param \Drupal\changelogify\ChangeSet\ChangeSet[] $changeSets
   *   Selected change sets.
   *
   * @return array<string, array<string, mixed>>
   *   Redacted payload data.
   */
  public function build(array $changeSets): array {
    $policy = $this->configFactory->get('changelogify_ai.settings')->get('policy') ?: [];
    $payload = [];
    foreach ($changeSets as $changeSet) {
      $context = $changeSet->summaryContext;
      $payload[$changeSet->id] = [
        'id' => $changeSet->id,
        'kind' => $changeSet->kind,
        'section' => $changeSet->suggestedSection,
        'summary' => $this->clean((string) ($context['message'] ?? '')),
        'source_event_ids' => $this->redactIds(
          $changeSet->sourceEventIds,
          $policy['entity_ids'] ?? 'redact',
        ),
      ];
      foreach (['username', 'actor_id', 'path', 'unpublished_label', 'bundle_label', 'changed_field_name'] as $key) {
        if (isset($context[$key])) {
          $payload[$changeSet->id][$key] = $this->apply($context[$key], $policy[$key . 's'] ?? 'redact');
        }
      }
      $allowlistedValues = $this->allowlistedValues($context['field_values'] ?? [], $policy['allowlisted_values'] ?? []);
      if ($allowlistedValues !== []) {
        $payload[$changeSet->id]['field_values'] = $allowlistedValues;
      }
    }
    return $payload;
  }

  /**
   * Creates a retention-safe payload hash.
   *
   * @param array<string, array<string, mixed>> $payload
   *   Redacted payload.
   *
   * @return string
   *   SHA-256 payload hash.
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
   * Applies a configured treatment to one scalar value.
   */
  private function apply(mixed $value, string $treatment): string {
    return $treatment === 'include' ? $this->clean((string) $value) : '[redacted]';
  }

  /**
   * Removes identifier values unless their explicit policy allows them.
   */
  private function redactIds(array $ids, string $treatment): array {
    return $treatment === 'include' ? array_values(array_map('intval', $ids)) : [];
  }

  /**
   * Includes only explicitly named scalar values from structured context.
   *
   * @param mixed $values
   *   Candidate field values, if a source supplies them.
   * @param mixed $allowlist
   *   Configured allowed field names.
   *
   * @return array<string, string>
   *   Cleaned values keyed by an explicitly allowed field name.
   */
  private function allowlistedValues(mixed $values, mixed $allowlist): array {
    if (!is_array($values) || !is_array($allowlist)) {
      return [];
    }
    $names = [];
    foreach ($allowlist as $key => $value) {
      $names[] = is_string($key) ? $key : (string) $value;
    }
    $included = [];
    foreach ($values as $name => $value) {
      if (is_string($name) && is_scalar($value) && in_array($name, $names, TRUE)) {
        $included[$name] = $this->clean((string) $value);
      }
    }
    return $included;
  }

  /**
   * Removes markup and control characters from a scalar payload value.
   */
  private function clean(string $value): string {
    $withoutExecutableContent = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@is', '', $value) ?? '';
    $stripped = strip_tags($withoutExecutableContent);
    return trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $stripped) ?? $stripped);
  }

}
