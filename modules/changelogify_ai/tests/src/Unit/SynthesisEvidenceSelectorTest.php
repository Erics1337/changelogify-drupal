<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify_ai\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\changelogify\ChangeSet\ChangeSet;
use Drupal\changelogify_ai\OutboundPayloadBuilder;
use Drupal\changelogify_ai\SynthesisEvidenceSelector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests exact eligible evidence boundaries and one-time exclusions.
 *
 * @group changelogify_ai
 */
#[Group('changelogify_ai')]
final class SynthesisEvidenceSelectorTest extends TestCase {

  /**
   * Eligible evidence is included by default and exclusions are composable.
   */
  public function testSelectsAllEligibleEvidenceUnlessExplicitlyExcluded(): void {
    $selector = $this->selector(['content', 'configuration']);
    $changeSets = [
      $this->changeSet('content-1', 'content_entity'),
      $this->changeSet('config-1', 'config'),
      $this->changeSet('user-1', 'user'),
    ];

    $default = $selector->select($changeSets);
    self::assertSame(['content-1', 'config-1'], array_keys($default['evidence']));
    self::assertSame(['user-1'], $default['ineligible_ids']);
    self::assertSame([], $default['excluded_editor_ids']);

    $filtered = $selector->select($changeSets, [
      'categories' => ['configuration'],
      'evidence' => ['content-1'],
    ]);
    self::assertSame([], $filtered['evidence']);
    self::assertSame(['content-1', 'config-1'], $filtered['excluded_editor_ids']);
    self::assertNotSame($default['fingerprint'], $filtered['fingerprint']);
  }

  /**
   * Unknown exclusions cannot expand or silently alter a reviewed boundary.
   */
  public function testRejectsUnknownExclusions(): void {
    $selector = $this->selector(['content']);
    $this->expectException(\UnexpectedValueException::class);
    $selector->select([$this->changeSet('content-1', 'content_entity')], [
      'evidence' => ['unknown-source'],
    ]);
  }

  /**
   * Creates a selector with deterministic site eligibility.
   */
  private function selector(array $eligibility): SynthesisEvidenceSelector {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
      'policy' => [],
      'eligibility.categories' => $eligibility,
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('changelogify_ai.settings')->willReturn($config);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('changelogify_event')->willReturn($storage);
    return new SynthesisEvidenceSelector(new OutboundPayloadBuilder($factory, $entityTypeManager));
  }

  /**
   * Creates fallback-only source evidence without an event entity.
   */
  private function changeSet(string $id, string $source): ChangeSet {
    return new ChangeSet(
      $id,
      'test',
      1,
      2,
      [],
      'changed',
      ['message' => "Evidence {$id}.", 'source' => $source],
      ['event_count' => 1, 'evidence_status' => 'available'],
    );
  }

}
