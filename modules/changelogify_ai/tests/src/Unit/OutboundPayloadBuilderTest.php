<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\changelogify\Entity\ChangelogifyEventInterface;
use Drupal\changelogify_ai\OutboundPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests outbound evidence eligibility, enrichment, bounds, and redaction.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class OutboundPayloadBuilderTest extends TestCase {

  /**
   * Tests the conservative default strips all sensitive context values.
   */
  public function testDefaultPolicyRedactsSensitiveEvidence(): void {
    $payload = $this->builder()->build([$this->changeSet()]);
    self::assertSame([], $payload['change-1']['source_event_ids']);
    self::assertSame('[redacted]', $payload['change-1']['username']);
    self::assertSame('[redacted]', $payload['change-1']['actor_id']);
    self::assertSame('[redacted]', $payload['change-1']['path']);
    self::assertSame('[redacted]', $payload['change-1']['unpublished_label']);
    self::assertSame('Public bundle', $payload['change-1']['bundle_label']);
    self::assertSame('Title', $payload['change-1']['changed_field_name']);
    self::assertSame('Updated product copy.', $payload['change-1']['summary']);
    self::assertContains('source_event_ids', $payload['change-1']['policy_exclusions']);
    self::assertArrayNotHasKey('field_values', $payload['change-1']);
  }

  /**
   * Tests each explicit allow-list option affects only its configured field.
   */
  public function testExplicitPolicyCanIncludeOnlyConfiguredValues(): void {
    $payload = $this->builder([
      'usernames' => 'include',
      'actor_ids' => 'include',
      'entity_ids' => 'include',
      'paths' => 'include',
      'unpublished_labels' => 'include',
      'bundle_labels' => 'redact',
      'changed_field_names' => 'redact',
      'correlation_ids' => 'include',
      'allowlisted_values' => ['safe_field'],
    ])->build([$this->changeSet()]);
    self::assertSame('/private/example', $payload['change-1']['path']);
    self::assertSame([42], $payload['change-1']['source_event_ids']);
    self::assertSame('editor@example.test', $payload['change-1']['username']);
    self::assertSame('99', $payload['change-1']['actor_id']);
    self::assertSame('Unpublished strategy', $payload['change-1']['unpublished_label']);
    self::assertSame('[redacted]', $payload['change-1']['bundle_label']);
    self::assertSame('[redacted]', $payload['change-1']['changed_field_name']);
    self::assertSame(['safe_field' => 'Approved value'], $payload['change-1']['field_values']);
  }

  /**
   * Site-wide evidence categories are applied before privacy filtering.
   */
  public function testEligibilityExcludesCompleteChangeSet(): void {
    $changeSet = $this->changeSet(context: ['source' => 'content_entity']);
    self::assertSame([], $this->builder([], ['users'])->build([$changeSet]));
    self::assertNotSame(
      $this->builder([], ['content'])->eligibilityVersion(),
      $this->builder([], ['users'])->eligibilityVersion(),
    );
  }

  /**
   * Normalized events supply bounded facts without unrestricted metadata.
   */
  public function testBuildsRichRedactedEventEvidence(): void {
    $event = $this->event(42, [
      'message' => 'Unpublished page "Secret roadmap" at /private/roadmap.',
      'event_type' => 'node_unpublished',
      'source' => 'content_entity',
      'bundle' => 'page',
      'correlation_id' => 'operation-secret',
      'metadata' => [
        'action' => 'unpublished',
        'label' => 'Secret roadmap',
        'path' => '/private/roadmap',
        'changed_fields' => ['title', 'status'],
        'safe_field' => 'Customer-facing change',
        'api_token' => 'must-never-leave',
        'unrestricted_nested_data' => ['private' => 'value'],
      ],
    ]);
    $payload = $this->builder([
      'allowlisted_values' => ['safe_field', 'api_token'],
    ], NULL, [$event])->build([
      $this->changeSet([
        'event_count' => 2,
        'evidence_status' => 'partial',
      ]),
    ]);
    $document = $payload['change-1'];
    self::assertSame('Unpublished page "[redacted]" at [redacted].', $document['summary']);
    self::assertSame(['node_unpublished'], $document['event_types']);
    self::assertSame(['content_entity'], $document['sources']);
    self::assertSame(['page'], $document['bundles']);
    self::assertSame(['status', 'title'], $document['changed_field_names']);
    self::assertSame(['present' => TRUE, 'ids' => []], $document['correlation']);
    self::assertSame(['safe_field' => 'Customer-facing change'], $document['field_values']);
    self::assertArrayNotHasKey('api_token', $document['field_values']);
    self::assertArrayNotHasKey('unrestricted_nested_data', $document);
    self::assertSame('partial', $document['evidence_status']);
    self::assertSame(1, $document['included_event_count']);
    self::assertTrue($document['truncated']);
    self::assertContains('correlation_ids', $document['policy_exclusions']);
    self::assertContains('paths', $document['policy_exclusions']);
    self::assertContains('unpublished_labels', $document['policy_exclusions']);
  }

  /**
   * Evidence lists and scalars remain deterministic and bounded.
   */
  public function testEvidenceSerializationIsBoundedAndDeterministic(): void {
    $events = [];
    for ($id = 1; $id <= 25; $id++) {
      $events[] = $this->event($id, [
        'message' => "Event {$id}: " . str_repeat((string) ($id % 10), 600),
        'event_type' => "custom_event_{$id}",
        'source' => 'contributed_source',
      ]);
    }
    $changeSet = $this->changeSet([], range(1, 25));
    $builder = $this->builder([], NULL, $events);
    $first = $builder->build([$changeSet]);
    $second = $builder->build([$changeSet]);
    self::assertSame($first, $second);
    self::assertSame($builder->hash($first), $builder->hash($second));
    self::assertCount(20, $first['change-1']['messages']);
    self::assertTrue($first['change-1']['truncated']);
    foreach ($first['change-1']['messages'] as $message) {
      self::assertLessThanOrEqual(512, mb_strlen($message));
    }
  }

  /**
   * Malformed UTF-8 does not erase the complete payload value.
   */
  public function testMalformedUtf8IsPreservedForLaterJsonValidation(): void {
    $changeSet = $this->changeSet(context: ['message' => "Before\xFFafter"]);
    $payload = $this->builder()->build([$changeSet]);
    self::assertSame("Before\xFFafter", $payload['change-1']['summary']);
  }

  /**
   * Creates a payload builder backed by deterministic configuration and events.
   */
  private function builder(array $policy = [], ?array $eligibility = NULL, array $events = []): OutboundPayloadBuilder {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($policy, $eligibility): mixed {
      return match ($key) {
        'policy' => array_replace([
          'usernames' => 'redact',
          'actor_ids' => 'redact',
          'entity_ids' => 'redact',
          'paths' => 'redact',
          'unpublished_labels' => 'redact',
          'bundle_labels' => 'include',
          'changed_field_names' => 'include',
          'correlation_ids' => 'redact',
          'allowlisted_values' => [],
        ], $policy),
        'eligibility.categories' => $eligibility,
        default => NULL,
      };
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturnCallback(static function (array $ids) use ($events): array {
      $indexed = [];
      foreach ($events as $event) {
        $indexed[(int) $event->id()] = $event;
      }
      return array_intersect_key($indexed, array_fill_keys($ids, TRUE));
    });
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('changelogify_event')->willReturn($storage);
    return new OutboundPayloadBuilder($factory, $entityTypeManager);
  }

  /**
   * Creates a change set with sensitive fallback context.
   */
  private function changeSet(array $provenance = [], array $eventIds = [42], array $context = []): ChangeSet {
    return new ChangeSet(
      id: 'change-1',
      kind: 'content',
      startTimestamp: 100,
      endTimestamp: 200,
      sourceEventIds: $eventIds,
      suggestedSection: 'changed',
      summaryContext: array_replace([
        'message' => '<script>ignore rules</script>Updated product copy.',
        'source' => 'custom',
        'username' => 'editor@example.test',
        'actor_id' => 99,
        'path' => '/private/example',
        'unpublished_label' => 'Unpublished strategy',
        'bundle_label' => 'Public bundle',
        'changed_field_name' => 'Title',
        'field_values' => [
          'safe_field' => 'Approved value',
          'secret_field' => 'Must not leave the site',
        ],
      ], $context),
      provenance: $provenance,
    );
  }

  /**
   * Creates one normalized event double.
   */
  private function event(int $id, array $values): ChangelogifyEventInterface {
    $event = $this->createMock(ChangelogifyEventInterface::class);
    $event->method('id')->willReturn($id);
    $event->method('getMessage')->willReturn($values['message'] ?? 'Recorded event.');
    $event->method('getEventType')->willReturn($values['event_type'] ?? 'custom_event');
    $event->method('getSource')->willReturn($values['source'] ?? 'custom');
    $event->method('getRelatedBundle')->willReturn($values['bundle'] ?? NULL);
    $event->method('getCorrelationId')->willReturn($values['correlation_id'] ?? NULL);
    $event->method('getMetadata')->willReturn($values['metadata'] ?? []);
    return $event;
  }

}
