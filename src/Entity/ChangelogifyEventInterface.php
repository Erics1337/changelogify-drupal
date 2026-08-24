<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for Changelogify Event entities.
 */
interface ChangelogifyEventInterface extends ContentEntityInterface {

  /**
   * Gets the normalized contract schema version.
   */
  public function getSchemaVersion(): int;

  /**
   * Gets the event timestamp.
   */
  public function getTimestamp(): int;

  /**
   * Gets the event type.
   */
  public function getEventType(): string;

  /**
   * Gets the event source.
   */
  public function getSource(): string;

  /**
   * Gets the related entity type ID, if any.
   */
  public function getRelatedEntityTypeId(): ?string;

  /**
   * Gets the related entity ID, if any.
   */
  public function getRelatedEntityId(): ?int;

  /**
   * Gets the related entity bundle, if any.
   */
  public function getRelatedBundle(): ?string;

  /**
   * Gets the event message.
   */
  public function getMessage(): string;

  /**
   * Gets the section hint.
   */
  public function getSectionHint(): ?string;

  /**
   * Gets the correlation ID, if any.
   */
  public function getCorrelationId(): ?string;

  /**
   * Gets the metadata array.
   */
  public function getMetadata(): array;

  /**
   * Sets the metadata array.
   */
  public function setMetadata(array $metadata): self;

}
