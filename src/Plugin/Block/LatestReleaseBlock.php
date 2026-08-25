<?php

declare(strict_types=1);

namespace Drupal\changelogify\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Displays the latest accessible published release.
 */
#[Block(
  id: 'changelogify_latest_release',
  admin_label: new TranslatableMarkup('Changelogify: Latest release'),
  category: new TranslatableMarkup('Changelogify'),
)]
final class LatestReleaseBlock extends ReleaseBlockBase {

  /**
   * {@inheritdoc}
   */
  protected function supportsMultipleReleases(): bool {
    return FALSE;
  }

}
