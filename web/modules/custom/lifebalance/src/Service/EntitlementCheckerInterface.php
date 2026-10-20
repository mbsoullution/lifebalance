<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;

/**
 * The only Premium check in the application.
 */
interface EntitlementCheckerInterface {

  /**
   * Whether this account has one feature at the given instant.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param string $feature
   *   Feature id, such as premium_criteria.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function hasFeatureAccess(AccountInterface $account, string $feature, ?int $at = NULL): bool;

  /**
   * Free, trial, premium, or expired, plus active sources and the latest end.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to summarize.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function getEffectiveStatus(AccountInterface $account, ?int $at = NULL): EffectiveStatus;

  /**
   * Whether this account has Premium at the given instant.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function hasPremium(AccountInterface $account, ?int $at = NULL): bool;

  /**
   * Active Premium records at the given instant.
   *
   * Access is the union of these records. They may overlap.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param int|null $at
   *   Unix time. NULL means now.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Active records.
   */
  public function getActive(AccountInterface $account, ?int $at = NULL): array;

  /**
   * Cache metadata for output that depends on hasPremium().
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose access may change.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function getCacheability(AccountInterface $account, ?int $at = NULL): CacheableMetadata;

}
