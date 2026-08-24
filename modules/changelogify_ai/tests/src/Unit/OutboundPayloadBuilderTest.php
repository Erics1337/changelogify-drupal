<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\changelogify_ai\OutboundPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests outbound-payload minimization and redaction.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class OutboundPayloadBuilderTest extends TestCase {

  /**
   * Tests the conservative default strips all sensitive context values.
   */
  public function testDefaultPolicyRedactsSensitiveEvidence(): void {
    $payload = $this->builder([])->build([$this->changeSet()]);
    self::assertSame([], $payload['change-1']['source_event_ids']);
    self::assertSame('[redacted]', $payload['change-1']['username']);
    self::assertSame('[redacted]', $payload['change-1']['actor_id']);
    self::assertSame('[redacted]', $payload['change-1']['path']);
    self::assertSame('[redacted]', $payload['change-1']['unpublished_label']);
    self::assertSame('Public bundle', $payload['change-1']['bundle_label']);
    self::assertSame('Title', $payload['change-1']['changed_field_name']);
    self::assertSame('Updated product copy.', $payload['change-1']['summary']);
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
   * Malformed UTF-8 does not erase the complete payload value.
   */
  public function testMalformedUtf8IsPreservedForLaterJsonValidation(): void {
    $changeSet = $this->changeSet();
    $changeSet->summaryContext['message'] = "Before\xFFafter";
    $payload = $this->builder([])->build([$changeSet]);
    self::assertSame("Before\xFFafter", $payload['change-1']['summary']);
  }

  /**
   * Creates a payload builder backed by a deterministic configuration double.
   */
  private function builder(array $policy): OutboundPayloadBuilder {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('policy')->willReturn(array_replace([
      'usernames' => 'redact',
      'actor_ids' => 'redact',
      'entity_ids' => 'redact',
      'paths' => 'redact',
      'unpublished_labels' => 'redact',
      'bundle_labels' => 'include',
      'changed_field_names' => 'include',
      'allowlisted_values' => [],
    ], $policy));
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    return new OutboundPayloadBuilder($factory);
  }

  /**
   * Creates a change set that includes hostile-looking and sensitive context.
   */
  private function changeSet(): object {
    return new class() {

      /** Stable change-set identifier. */
      public string $id = 'change-1';

      /** Change-set type. */
      public string $kind = 'content';

      /** Source event IDs. */
      public array $sourceEventIds = [42];

      /** Suggested release section. */
      public string $suggestedSection = 'changed';

      /** Payload source context. */
      public array $summaryContext = [
        'message' => '<script>ignore rules</script>Updated product copy.',
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
      ];

    };
  }

}
