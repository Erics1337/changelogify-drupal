<?php

declare(strict_types=1);

namespace Drupal\Tests\changelogify\Unit;

use Drupal\changelogify\ConfigClassifier\ConfigClassification;
use Drupal\changelogify\ConfigClassifier\ConfigClassifier;
use Drupal\changelogify\ConfigClassifier\ConfigClassifierExtensionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests stable Drupal configuration classification.
 *
 * @group changelogify
 */
#[Group('changelogify')]
final class ConfigClassifierTest extends TestCase {

  /**
   * Tests built-in core and common categories.
   *
   * @dataProvider coreCategoryProvider
   */
  #[DataProvider('coreCategoryProvider')]
  public function testCoreCategories(
    string $name,
    string $category,
    ?string $owner,
    bool $sensitive = FALSE,
  ): void {
    $result = (new ConfigClassifier([]))->classify($name);
    self::assertSame($name, $result->configName);
    self::assertSame($category, $result->category);
    self::assertSame($owner, $result->owningExtension);
    self::assertSame($sensitive, $result->sensitive);
  }

  /**
   * Provides representative configuration names.
   */
  public static function coreCategoryProvider(): array {
    return [
      'view' => ['views.view.content', 'view', 'views'],
      'field storage' => ['field.storage.node.field_summary', 'field_storage', 'field'],
      'field instance' => ['field.field.node.page.field_summary', 'field_instance', 'field'],
      'form display' => ['core.entity_form_display.node.page.default', 'entity_form_display', 'field_ui'],
      'view display' => ['core.entity_view_display.node.page.default', 'entity_view_display', 'field_ui'],
      'block' => ['block.block.site_branding', 'block_placement', 'block'],
      'menu' => ['system.menu.main', 'menu', 'system'],
      'workflow' => ['workflows.workflow.editorial', 'workflow', 'workflows'],
      'role is sensitive' => ['user.role.editor', 'role', 'user', TRUE],
      'text format is sensitive' => ['filter.format.full_html', 'text_format', 'filter', TRUE],
      'image style' => ['image.style.large', 'image_style', 'image'],
      'extensions are sensitive' => ['core.extension', 'extensions', 'system', TRUE],
      'theme settings' => ['system.theme.global', 'theme_settings', 'system'],
      'module settings' => ['changelogify.settings', 'general_settings', 'changelogify'],
    ];
  }

  /**
   * Tests unknown names and non-default collections are retained.
   */
  public function testUnknownNameAndCollection(): void {
    $result = (new ConfigClassifier([]))->classify('example.unknown_object', 'language.fr');
    self::assertSame('other_configuration', $result->category);
    self::assertSame('example.unknown_object', $result->configName);
    self::assertSame('language.fr', $result->collection);
  }

  /**
   * Tests contributed overrides resolve by priority and are cached safely.
   */
  public function testContributedOverridesAreDeterministic(): void {
    $classifier = new ConfigClassifier([
      new LowPriorityTestClassifier(),
      new HighPriorityTestClassifier(),
    ]);
    $first = $classifier->classify('views.view.content', 'language.es');
    $second = $classifier->classify('views.view.content', 'language.es');

    self::assertSame('high_override', $first->category);
    self::assertSame('language.es', $first->collection);
    self::assertSame($first, $second);
  }

  /**
   * Tests equal-priority conflicts resolve by class name.
   */
  public function testEqualPriorityUsesClassName(): void {
    $classifier = new ConfigClassifier([
      new ZetaPriorityTestClassifier(),
      new AlphaPriorityTestClassifier(),
    ]);
    self::assertSame('alpha_override', $classifier->classify('example.item')->category);
  }

}

/**
 * Base test classifier.
 */
abstract class TestConfigClassifierBase implements ConfigClassifierExtensionInterface {

  /**
   * {@inheritdoc} */
  public function supports(string $configName, string $collection): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc} */
  public function classify(string $configName, string $collection): ConfigClassification {
    return new ConfigClassification($configName, $collection, $this->category(), 'Override');
  }

  /**
   * Gets the fixture category.
   */
  abstract protected function category(): string;

}

/**
 * Low-priority classifier fixture.
 */
final class LowPriorityTestClassifier extends TestConfigClassifierBase {

  /**
   * {@inheritdoc} */
  public function getPriority(): int {
    return 10;
  }

  /**
   * {@inheritdoc} */
  protected function category(): string {
    return 'low_override';
  }

}

/**
 * High-priority classifier fixture.
 */
final class HighPriorityTestClassifier extends TestConfigClassifierBase {

  /**
   * {@inheritdoc} */
  public function getPriority(): int {
    return 100;
  }

  /**
   * {@inheritdoc} */
  protected function category(): string {
    return 'high_override';
  }

}

/**
 * Alphabetically first equal-priority classifier fixture.
 */
final class AlphaPriorityTestClassifier extends TestConfigClassifierBase {

  /**
   * {@inheritdoc} */
  public function getPriority(): int {
    return 50;
  }

  /**
   * {@inheritdoc} */
  protected function category(): string {
    return 'alpha_override';
  }

}

/**
 * Alphabetically last equal-priority classifier fixture.
 */
final class ZetaPriorityTestClassifier extends TestConfigClassifierBase {

  /**
   * {@inheritdoc} */
  public function getPriority(): int {
    return 50;
  }

  /**
   * {@inheritdoc} */
  protected function category(): string {
    return 'zeta_override';
  }

}
