<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for Changelogify Release entities.
 */
interface ChangelogifyReleaseInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface
{

    /**
     * Gets the release title.
     */
    public function getTitle(): string;

    /**
     * Sets the release title.
     */
    public function setTitle(string $title): self;

    /**
     * Returns whether the release is published.
     */
    public function isPublished(): bool;

    /**
     * Sets the published status.
     */
    public function setPublished(bool $published = TRUE): self;

    /**
     * Gets the sections array.
     */
    public function getSections(): array;

    /**
     * Sets the sections array.
     */
    public function setSections(array $sections): self;

    /**
     * Gets the release date timestamp.
     */
    public function getReleaseDate(): int;

    /**
     * Gets the version string.
     */
    public function getVersion(): ?string;

}
