<?php

declare(strict_types=1);

namespace Drupal\changelogify\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for Changelogify Release entities.
 */
interface ChangelogifyReleaseInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

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
   * Gets the editorial workflow state.
   */
  public function getEditorialState(): string;

  /**
   * Sets the editorial workflow state and authoritative publication status.
   */
  public function setEditorialState(string $state): self;

  /**
   * Gets the stable current public slug.
   */
  public function getSlug(): string;

  /**
   * Gets prior public slugs retained for canonical redirects.
   *
   * @return string[]
   *   Historical slugs in oldest-first order.
   */
  public function getSlugHistory(): array;

  /**
   * Sets validated prior public slugs.
   *
   * @param string[] $history
   *   Historical slugs.
   */
  public function setSlugHistory(array $history): self;

  /**
   * Gets the sections array.
   */
  public function getSections(): array;

  /**
   * Sets the sections array.
   */
  public function setSections(array $sections): self;

  /**
   * Gets privacy-bounded release provenance.
   */
  public function getProvenance(): array;

  /**
   * Sets privacy-bounded release provenance.
   */
  public function setProvenance(array $provenance): self;

  /**
   * Gets the release date timestamp.
   */
  public function getReleaseDate(): int;

  /**
   * Gets the version string.
   */
  public function getVersion(): ?string;

  /**
   * Gets the scheduled publication timestamp, or zero when unscheduled.
   */
  public function getScheduledPublicationTime(): int;

  /**
   * Gets the reviewed revision approved for scheduled publication.
   */
  public function getScheduledRevisionId(): ?int;

  /**
   * Sets or clears scheduled publication metadata.
   */
  public function setPublicationSchedule(int $timestamp = 0, ?int $revisionId = NULL): self;

}
