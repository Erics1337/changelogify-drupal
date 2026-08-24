<?php

declare(strict_types=1);

namespace Drupal\changelogify;

/**
 * Immutable, normalized input for a Changelogify event.
 */
final class EventInput {

  public const SCHEMA_VERSION = 1;

  public const SECTIONS = [
    'added',
    'changed',
    'fixed',
    'removed',
    'security',
    'other',
  ];

  /**
   * Constructs and validates an event input.
   */
  public function __construct(
    public readonly string $eventType,
    public readonly string $source,
    public readonly string $message,
    public readonly int $timestamp,
    public readonly int $actorId = 0,
    public readonly ?string $entityTypeId = NULL,
    public readonly ?int $entityId = NULL,
    public readonly ?string $bundle = NULL,
    public readonly ?string $sectionHint = NULL,
    public readonly array $metadata = [],
    public readonly ?string $correlationId = NULL,
    public readonly int $schemaVersion = self::SCHEMA_VERSION,
  ) {
    $this->validateIdentifier($eventType, 'event type', 64, TRUE);
    $this->validateIdentifier($source, 'source', 64, TRUE);
    $this->validateString($message, 'message', 512);
    $this->validateIdentifier($entityTypeId, 'entity type ID', 64);
    $this->validateIdentifier($bundle, 'bundle', 64);
    $this->validateIdentifier($correlationId, 'correlation ID', 128, FALSE, '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/');

    if ($timestamp < 1) {
      throw new \InvalidArgumentException('Event timestamp must be a positive Unix timestamp.');
    }
    if ($actorId < 0) {
      throw new \InvalidArgumentException('Event actor ID cannot be negative.');
    }
    if ($entityId !== NULL && $entityId < 0) {
      throw new \InvalidArgumentException('Related entity ID cannot be negative.');
    }
    if ($sectionHint !== NULL && !in_array($sectionHint, self::SECTIONS, TRUE)) {
      throw new \InvalidArgumentException('Event section hint is invalid.');
    }
    if ($schemaVersion !== self::SCHEMA_VERSION) {
      throw new \InvalidArgumentException(sprintf('Unsupported event schema version: %d.', $schemaVersion));
    }

    try {
      json_encode($metadata, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \InvalidArgumentException('Event metadata must be JSON-serializable.', 0, $exception);
    }
  }

  /**
   * Adapts the public 1.x array shape to the normalized contract.
   */
  public static function fromArray(array $data, int $defaultTimestamp, int $defaultActorId): self {
    foreach (['event_type', 'source', 'message'] as $key) {
      if (!isset($data[$key]) || !is_string($data[$key])) {
        throw new \InvalidArgumentException(sprintf('Event data key "%s" must be a non-empty string.', $key));
      }
    }
    if (isset($data['metadata']) && !is_array($data['metadata'])) {
      throw new \InvalidArgumentException('Event metadata must be an array.');
    }

    return new self(
      eventType: trim($data['event_type']),
      source: trim($data['source']),
      message: trim($data['message']),
      timestamp: self::integerValue($data['timestamp'] ?? $defaultTimestamp, 'timestamp'),
      actorId: self::integerValue($data['user_id'] ?? $defaultActorId, 'actor ID'),
      entityTypeId: self::nullableString($data['entity_type_id'] ?? NULL, 'entity type ID'),
      entityId: isset($data['entity_id']) ? self::integerValue($data['entity_id'], 'entity ID') : NULL,
      bundle: self::nullableString($data['bundle'] ?? NULL, 'bundle'),
      sectionHint: self::nullableString($data['section_hint'] ?? NULL, 'section hint'),
      metadata: $data['metadata'] ?? [],
      correlationId: self::nullableString($data['correlation_id'] ?? NULL, 'correlation ID'),
      schemaVersion: self::integerValue($data['schema_version'] ?? self::SCHEMA_VERSION, 'schema version'),
    );
  }

  /**
   * Validates a bounded machine identifier.
   */
  private function validateIdentifier(
    ?string $value,
    string $label,
    int $length,
    bool $required = FALSE,
    string $pattern = '/^[a-z][a-z0-9_]*$/',
  ): void {
    if ($value === NULL && !$required) {
      return;
    }
    if ($value === NULL || $value === '' || strlen($value) > $length || preg_match($pattern, $value) !== 1) {
      throw new \InvalidArgumentException(sprintf('Event %s must be a valid identifier of at most %d characters.', $label, $length));
    }
  }

  /**
   * Validates a required bounded string.
   */
  private function validateString(string $value, string $label, int $length): void {
    if (trim($value) === '' || strlen($value) > $length) {
      throw new \InvalidArgumentException(sprintf('Event %s must be non-empty and at most %d characters.', $label, $length));
    }
  }

  /**
   * Normalizes a strict integer value from the compatibility API.
   */
  private static function integerValue(mixed $value, string $label): int {
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
      throw new \InvalidArgumentException(sprintf('Event %s must be an integer.', $label));
    }
    return (int) $value;
  }

  /**
   * Normalizes an optional string from the compatibility API.
   */
  private static function nullableString(mixed $value, string $label): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value)) {
      throw new \InvalidArgumentException(sprintf('Event %s must be a string.', $label));
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

}
