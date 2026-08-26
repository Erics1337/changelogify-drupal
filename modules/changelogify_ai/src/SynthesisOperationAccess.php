<?php

declare(strict_types=1);

namespace Drupal\changelogify_ai;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls creator and privileged access to synthesis operations.
 */
final class SynthesisOperationAccess {

  public function __construct(private readonly SynthesisJobManager $jobs) {}

  /**
   * Allows creators to view their jobs and privileged users to view all.
   */
  public function view(AccountInterface $account, string $job_id): AccessResultInterface {
    $job = $this->jobs->get($job_id);
    if (!is_array($job)) {
      return AccessResult::forbidden()->addCacheContexts(['user.permissions']);
    }
    $privileged = $account->hasPermission('view changelogify ai history');
    $owner = (int) ($job['actor'] ?? 0) > 0
      && (int) $job['actor'] === (int) $account->id()
      && $account->hasPermission('use changelogify ai');
    return AccessResult::allowedIf($privileged || $owner)
      ->addCacheContexts(['user', 'user.permissions'])
      ->setCacheMaxAge(0);
  }

  /**
   * Allows owner cancellation while retaining administrator authority.
   */
  public function cancel(AccountInterface $account, string $operation_id): AccessResultInterface {
    $job = $this->jobs->get($operation_id);
    if (is_array($job)) {
      $active = in_array($job['status'] ?? NULL, ['queued', 'running'], TRUE);
      $privileged = $account->hasPermission('administer changelogify ai');
      $owner = (int) ($job['actor'] ?? 0) > 0
        && (int) $job['actor'] === (int) $account->id()
        && $account->hasPermission('use changelogify ai');
      return AccessResult::allowedIf($active && ($privileged || $owner))
        ->addCacheContexts(['user', 'user.permissions'])
        ->setCacheMaxAge(0);
    }
    return AccessResult::allowedIf($account->hasPermission('administer changelogify ai'))
      ->addCacheContexts(['user.permissions'])
      ->setCacheMaxAge(0);
  }

}
