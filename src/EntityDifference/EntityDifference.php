<?php

declare(strict_types=1);

namespace Drupal\changelogify\EntityDifference;

/**
 * Immutable, privacy-bounded result of comparing two entity revisions.
 */
final class EntityDifference {

  /**
   * Constructs a normalized difference.
   *
   * @param string[] $changedFields
   *   Changed field machine names.
   * @param string|null $publicationTransition
   *   A published/unpublished transition, if one occurred.
   * @param array<string, array{old: array<int, int|string>, new: array<int, int|string>}> $references
   *   Safe reference target identifiers by field.
   * @param array<string, array{old: scalar|null, new: scalar|null}> $scalarValues
   *   Explicitly allowed, bounded scalar values by field.
   */
  public function __construct(
    public readonly array $changedFields = [],
    public readonly ?string $publicationTransition = NULL,
    public readonly array $references = [],
    public readonly array $scalarValues = [],
  ) {
  }

  /**
   * Determines whether the comparison found any safe difference.
   */
  public function isEmpty(): bool {
    return $this->changedFields === []
      && $this->publicationTransition === NULL
      && $this->references === []
      && $this->scalarValues === [];
  }

  /**
   * Converts the result to normalized event metadata.
   */
  public function toArray(): array {
    return array_filter([
      'changed_fields' => $this->changedFields,
      'publication_transition' => $this->publicationTransition,
      'references' => $this->references,
      'scalar_values' => $this->scalarValues,
    ], static fn (mixed $value): bool => $value !== NULL && $value !== []);
  }

}
