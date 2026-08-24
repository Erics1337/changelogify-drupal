<?php

declare(strict_types=1);

namespace Drupal\changelogify\ConfigClassifier;

/**
 * Provides core rules and deterministic contributed classifier overrides.
 */
final class ConfigClassifier implements ConfigClassifierInterface {

  /**
   * Request-local classifications keyed by collection and name.
   *
   * @var array<string, \Drupal\changelogify\ConfigClassifier\ConfigClassification>
   */
  private array $classifications = [];

  /**
   * Sorted contributed classifiers.
   *
   * @var \Drupal\changelogify\ConfigClassifier\ConfigClassifierExtensionInterface[]|null
   */
  private ?array $sortedExtensions = NULL;

  /**
   * Constructs the classifier.
   *
   * @param iterable<\Drupal\changelogify\ConfigClassifier\ConfigClassifierExtensionInterface> $extensions
   *   Tagged classifier extensions.
   */
  public function __construct(
    private readonly iterable $extensions,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function classify(string $configName, string $collection = 'default'): ConfigClassification {
    $configName = trim($configName);
    $collection = trim($collection) ?: 'default';
    if ($configName === '') {
      throw new \InvalidArgumentException('Configuration name must be non-empty.');
    }
    $cacheKey = $collection . ':' . $configName;
    if (isset($this->classifications[$cacheKey])) {
      return $this->classifications[$cacheKey];
    }

    foreach ($this->getExtensions() as $extension) {
      if ($extension->supports($configName, $collection)) {
        return $this->classifications[$cacheKey] = $extension->classify($configName, $collection);
      }
    }
    return $this->classifications[$cacheKey] = $this->classifyCore($configName, $collection);
  }

  /**
   * Applies built-in Drupal core and common configuration rules.
   */
  private function classifyCore(string $name, string $collection): ConfigClassification {
    $rules = [
      'views.view.' => ['view', 'View', 'views', FALSE],
      'field.storage.' => ['field_storage', 'Field storage', 'field', FALSE],
      'field.field.' => ['field_instance', 'Field', 'field', FALSE],
      'core.entity_form_display.' => ['entity_form_display', 'Entity form display', 'field_ui', FALSE],
      'core.entity_view_display.' => ['entity_view_display', 'Entity view display', 'field_ui', FALSE],
      'block.block.' => ['block_placement', 'Block placement', 'block', FALSE],
      'system.menu.' => ['menu', 'Menu', 'system', FALSE],
      'workflows.workflow.' => ['workflow', 'Workflow', 'workflows', FALSE],
      'user.role.' => ['role', 'Role and permissions', 'user', TRUE],
      'filter.format.' => ['text_format', 'Text format', 'filter', TRUE],
      'image.style.' => ['image_style', 'Image style', 'image', FALSE],
      'core.extension' => ['extensions', 'Extensions', 'system', TRUE],
      'system.theme' => ['theme_settings', 'Theme settings', 'system', FALSE],
    ];
    foreach ($rules as $prefix => [$category, $label, $owner, $sensitive]) {
      if ($name === $prefix || str_starts_with($name, $prefix)) {
        return new ConfigClassification($name, $collection, $category, $label, $owner, $sensitive);
      }
    }

    if (str_ends_with($name, '.settings') || str_starts_with($name, 'system.')) {
      return new ConfigClassification(
        $name,
        $collection,
        'general_settings',
        'General settings',
        explode('.', $name, 2)[0],
      );
    }
    return new ConfigClassification(
      $name,
      $collection,
      'other_configuration',
      'Other configuration',
    );
  }

  /**
   * Gets contributed extensions in deterministic override order.
   */
  private function getExtensions(): array {
    if ($this->sortedExtensions !== NULL) {
      return $this->sortedExtensions;
    }
    $extensions = is_array($this->extensions)
      ? $this->extensions
      : iterator_to_array($this->extensions, FALSE);
    usort($extensions, static function (
      ConfigClassifierExtensionInterface $left,
      ConfigClassifierExtensionInterface $right,
    ): int {
      return $right->getPriority() <=> $left->getPriority()
        ?: get_class($left) <=> get_class($right);
    });
    return $this->sortedExtensions = $extensions;
  }

}
