<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Statistics are visible to metadata readers and to finance.
 */
final class StatsAccess {

  /**
   * Allows either the metadata permission or the payment statistics one.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermissions($account, [
      'view lifebalance user metadata',
      'view lifebalance stats',
    ], 'OR')->cachePerPermissions();
  }

}
