<?php

declare(strict_types=1);

namespace Drupal\changelogify\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Displays a configurable list of recent accessible published releases.
 */
#[Block(
  id: 'changelogify_recent_releases',
  admin_label: new TranslatableMarkup('Changelogify: Recent releases'),
  category: new TranslatableMarkup('Changelogify'),
)]
final class RecentReleasesBlock extends ReleaseBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function supportsMultipleReleases(): bool {
    return TRUE;
  }

}
