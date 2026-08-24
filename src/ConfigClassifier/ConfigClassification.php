<?php

declare(strict_types=1);

namespace Drupal\changelogify\ConfigClassifier;

/**
 * Immutable technical classification of one configuration object.
 */
final class ConfigClassification {

  public function __construct(
    public readonly string $configName,
    public readonly string $collection,
    public readonly string $category,
    public readonly string $categoryLabel,
    public readonly ?string $owningExtension = NULL,
    public readonly bool $sensitive = FALSE,
  ) {
  }

  /**
   * Converts classification metadata to a normalized array.
   */
  public function toArray(): array {
    return array_filter([
      'config_name' => $this->configName,
      'collection' => $this->collection,
      'category' => $this->category,
      'category_label' => $this->categoryLabel,
      'owning_extension' => $this->owningExtension,
      'sensitive' => $this->sensitive,
    ], static fn (mixed $value): bool => $value !== NULL && $value !== '');
  }

}
