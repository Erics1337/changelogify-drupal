<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\EventInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests the normalized event contract.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class EventInputTest extends TestCase {

  /**
   * Tests normalization from the legacy array API.
   */
  public function testLegacyArrayIsNormalized(): void {
    $input = EventInput::fromArray([
      'event_type' => ' content_created ',
      'source' => ' content_entity ',
      'message' => ' Created a page. ',
      'entity_id' => '42',
      'correlation_id' => 'deploy:123',
    ], 1_700_000_000, 7);

    self::assertSame(EventInput::SCHEMA_VERSION, $input->schemaVersion);
    self::assertSame('content_created', $input->eventType);
    self::assertSame('content_entity', $input->source);
    self::assertSame('Created a page.', $input->message);
    self::assertSame(42, $input->entityId);
    self::assertSame(7, $input->actorId);
    self::assertSame('deploy:123', $input->correlationId);
  }

  /**
   * Tests invalid values fail at contract construction.
   *
   * @dataProvider invalidInputProvider
   */
  #[DataProvider('invalidInputProvider')]
  public function testInvalidContractValuesAreRejected(array $overrides): void {
    $values = $overrides + [
      'eventType' => 'content_created',
      'source' => 'content_entity',
      'message' => 'Created a page.',
      'timestamp' => 1_700_000_000,
      'metadata' => [],
      'sectionHint' => 'added',
    ];

    $this->expectException(\InvalidArgumentException::class);
    new EventInput(...$values);
  }

  /**
   * Provides malformed contract values.
   */
  public static function invalidInputProvider(): array {
    $recursiveMetadata = [];
    $recursiveMetadata['self'] = &$recursiveMetadata;

    return [
      'invalid identifier' => [['eventType' => 'Content Created']],
      'non-positive timestamp' => [['timestamp' => 0]],
      'unknown section' => [['sectionHint' => 'future']],
      'invalid correlation identifier' => [['correlationId' => 'deploy/123']],
      'unsupported schema' => [['schemaVersion' => 2]],
      'non-serializable metadata' => [['metadata' => $recursiveMetadata]],
    ];
  }

}
