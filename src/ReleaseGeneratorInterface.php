<?php

declare(strict_types=1);

namespace Drupal\changelogify;

use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Interface for the Release Generator service.
 */
interface ReleaseGeneratorInterface
{

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
