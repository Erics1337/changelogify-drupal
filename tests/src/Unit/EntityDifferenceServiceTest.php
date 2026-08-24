<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\EntityDifference\EntityDifferenceService;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests privacy-aware entity comparison.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class EntityDifferenceServiceTest extends TestCase {

  /**
   * The service under test.
   */
  private EntityDifferenceService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->service = new EntityDifferenceService();
  }

  /**
   * Tests no-op saves and missing originals return empty results.
   */
  public function testNoOpAndMissingOriginalAreEmpty(): void {
    $entity = $this->entity(['title' => ['string', [['value' => 'Same']]]]);
    self::assertTrue($this->service->compare($entity, $entity)->isEmpty());
    self::assertTrue($this->service->compare($entity, NULL)->isEmpty());
  }

  /**
   * Tests scalar values require an explicit safe allowlist.
   */
  public function testScalarPrivacyAndBounds(): void {
    $original = $this->entity([
      'title' => ['string', [['value' => 'Old title']]],
      'api_token' => ['string', [['value' => 'old-secret']]],
      'summary' => ['string', [['value' => 'Short']]],
    ]);
    $updated = $this->entity([
      'title' => ['string', [['value' => 'New title']]],
      'api_token' => ['string', [['value' => 'new-secret']]],
      'summary' => ['string', [['value' => str_repeat('x', 129)]]],
    ]);

    $default = $this->service->compare($updated, $original);
    self::assertSame(['api_token', 'summary', 'title'], $default->changedFields);
    self::assertSame([], $default->scalarValues);

    $allowed = $this->service->compare($updated, $original, ['title', 'api_token', 'summary']);
    self::assertSame([
      'summary' => ['old' => 'Short', 'new' => '[redacted]'],
      'title' => ['old' => 'Old title', 'new' => 'New title'],
    ], $allowed->scalarValues);
  }

  /**
   * Tests reference, multivalue, unsupported, and computed fields.
   */
  public function testReferencesAndIgnoredFields(): void {
    $original = $this->entity([
      'related' => ['entity_reference', [['target_id' => 3], ['target_id' => 'uuid-old']]],
      'body' => ['text_long', [['value' => 'Private old body']]],
      'computed_total' => ['integer', [['value' => 1]], TRUE],
      'revision_id' => ['integer', [['value' => 10]]],
    ]);
    $updated = $this->entity([
      'related' => ['entity_reference', [['target_id' => 4], ['target_id' => 'uuid-new']]],
      'body' => ['text_long', [['value' => 'Private new body']]],
      'computed_total' => ['integer', [['value' => 2]], TRUE],
      'revision_id' => ['integer', [['value' => 11]]],
    ]);

    $difference = $this->service->compare($updated, $original, ['body']);
    self::assertSame(['body', 'related'], $difference->changedFields);
    self::assertSame([
      'related' => [
        'old' => [3, 'uuid-old'],
        'new' => [4, 'uuid-new'],
      ],
    ], $difference->references);
    self::assertSame([], $difference->scalarValues);
  }

  /**
   * Tests a publication transition is represented once.
   */
  public function testPublicationTransitionAppearsOnce(): void {
    $original = $this->entity(['status' => ['boolean', [['value' => 0]]]]);
    $updated = $this->entity(['status' => ['boolean', [['value' => 1]]]]);

    $difference = $this->service->compare($updated, $original, ['status']);
    self::assertSame('published', $difference->publicationTransition);
    self::assertSame([], $difference->changedFields);
    self::assertSame([], $difference->scalarValues);
    self::assertSame(['publication_transition' => 'published'], $difference->toArray());
  }

  /**
   * Tests translated field changes and deterministic field ordering.
   */
  public function testTranslatedComparisonIsDeterministic(): void {
    $original = $this->entity([
      'z_translated_title' => ['string', [['value' => 'Bonjour']]],
      'a_translated_summary' => ['string', [['value' => 'Ancien']]],
    ]);
    $updated = $this->entity([
      'z_translated_title' => ['string', [['value' => 'Salut']]],
      'a_translated_summary' => ['string', [['value' => 'Nouveau']]],
    ]);

    self::assertSame(
      ['a_translated_summary', 'z_translated_title'],
      $this->service->compare($updated, $original)->changedFields,
    );
  }

  /**
   * Creates a fieldable entity double from compact field definitions.
   */
  private function entity(array $fields): FieldableEntityInterface {
    $entity = $this->createMock(FieldableEntityInterface::class);
    $definitions = [];
    $lists = [];
    foreach ($fields as $name => $field) {
      [$type, $value] = $field;
      $computed = $field[2] ?? FALSE;
      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getType')->willReturn($type);
      $definition->method('isComputed')->willReturn($computed);
      $definition->method('isReadOnly')->willReturn(FALSE);
      $definition->method('getDefaultValue')->willReturn([]);
      $definitions[$name] = $definition;
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('getValue')->willReturn($value);
      $lists[$name] = $list;
    }
    $entity->method('getFieldDefinitions')->willReturn($definitions);
    $entity->method('hasField')->willReturnCallback(static fn (string $name): bool => isset($lists[$name]));
    $entity->method('get')->willReturnCallback(static fn (string $name): FieldItemListInterface => $lists[$name]);
    return $entity;
  }

}
