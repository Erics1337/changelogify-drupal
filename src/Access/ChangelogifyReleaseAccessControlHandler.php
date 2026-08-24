<?php

declare(strict_types=1);

namespace Drupal\changelogify\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\changelogify\Entity\ChangelogifyReleaseInterface;

/**
 * Controls access to Changelogify release entities.
 */
final class ChangelogifyReleaseAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    assert($entity instanceof ChangelogifyReleaseInterface);

    if (in_array($operation, ['view all revisions', 'view revision'], TRUE)) {
      return AccessResult::allowedIfHasPermission($account, 'view changelogify release revisions')
        ->addCacheableDependency($entity);
    }
    if ($operation === 'revert') {
      return AccessResult::allowedIfHasPermission($account, 'revert changelogify release revisions')
        ->addCacheableDependency($entity);
    }

    if ($account->hasPermission('manage changelogify releases')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'view' || $operation === 'view label') {
      return AccessResult::allowedIf($entity->isPublished())
        ->andIf(AccessResult::allowedIfHasPermission($account, 'view changelogify releases'))
        ->addCacheableDependency($entity);
    }

    return AccessResult::forbidden()
      ->cachePerPermissions()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'manage changelogify releases');
  }

}
