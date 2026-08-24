<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Interface for the Release Generator service.
 */
interface ReleaseGeneratorInterface {

  /**
   * Previews candidate change sets without creating a release.
   */
  public function previewRange(\DateTimeInterface $start, \DateTimeInterface $end): ReleasePreview;

  /**
   * Previews change sets since the latest published release.
   */
  public function previewSinceLast(): ReleasePreview;

  /**
   * Revalidates a preview selection and creates a draft release.
   *
   * @param \DateTimeInterface $start
   *   Inclusive release-window start.
   * @param \DateTimeInterface $end
   *   Inclusive release-window end.
   * @param array<string, string> $selection
   *   Map of selected stable change-set IDs to assigned sections. Multiple
   *   change-set ID keys may map to the same section without overwriting one
   *   another.
   * @param array $options
   *   Release title, version, and label options.
   * @param bool $allowEmpty
   *   Whether an explicitly confirmed empty draft may be created.
   * @param bool $allowEvidenceReuse
   *   Whether evidence already referenced by another release may be reused.
   */
  public function generateReleaseFromSelection(
    \DateTimeInterface $start,
    \DateTimeInterface $end,
    array $selection,
    array $options = [],
    bool $allowEmpty = FALSE,
    bool $allowEvidenceReuse = FALSE,
  ): ChangelogifyReleaseInterface;

  /**
   * Generates a draft release from events in a date range.
   *
   * @param \DateTimeInterface $start
   *   Start of the date range.
   * @param \DateTimeInterface $end
   *   End of the date range.
   * @param array $options
   *   Optional settings:
   *   - title: (string) Custom title for the release.
   *   - version: (string) Version string.
   *   - label_type: (string) Label type.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface
   *   The created draft release.
   */
  public function generateReleaseFromRange(\DateTimeInterface $start, \DateTimeInterface $end, array $options = []): ChangelogifyReleaseInterface;

  /**
   * Generates a draft release from events since the last release.
   *
   * @param array $options
   *   Optional settings.
   *
   * @return \Drupal\changelogify\Entity\ChangelogifyReleaseInterface
   *   The created draft release.
   */
  public function generateReleaseSinceLast(array $options = []): ChangelogifyReleaseInterface;

}
