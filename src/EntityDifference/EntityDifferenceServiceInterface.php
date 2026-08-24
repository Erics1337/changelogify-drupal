<?php

declare(strict_types=1);

namespace Drupal\changelogify\EntityDifference;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Compares fieldable entities without exposing arbitrary values.
 *
 * The default result contains field machine names, publication state changes,
 * and entity-reference target IDs only. Scalar values are returned only for
 * explicitly allowlisted fields, supported scalar field types, bounded values,
 * and fields whose names are not secret-like.
 */
interface EntityDifferenceServiceInterface {

  /**
   * Compares an updated entity with its original revision/translation.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $updated
   *   The updated entity or translation.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $original
   *   The matching original entity or translation, or NULL when unavailable.
   * @param string[] $scalarAllowlist
   *   Field names whose bounded scalar old/new values may be returned.
   */
  public function compare(
    FieldableEntityInterface $updated,
    ?FieldableEntityInterface $original,
    array $scalarAllowlist = [],
  ): EntityDifference;

}
