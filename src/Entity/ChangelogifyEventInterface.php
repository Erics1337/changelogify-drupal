<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for Changelogify Event entities.
 */
interface ChangelogifyEventInterface extends ContentEntityInterface {

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
   * Gets the event message.
   */
  public function getMessage(): string;

  /**
   * Gets the section hint.
   */
  public function getSectionHint(): ?string;

  /**
   * Gets the metadata array.
   */
  public function getMetadata(): array;

  /**
   * Sets the metadata array.
   */
  public function setMetadata(array $metadata): self;

}
