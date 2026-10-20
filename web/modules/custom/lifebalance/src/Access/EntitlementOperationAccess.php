<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\user\UserInterface;

/**
 * Route access for manual entitlement forms.
 *
 * Payment records stay closed here. Billing changes those later.
 */
final class EntitlementOperationAccess implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly Clock $clock,
  ) {}

  /**
   * Whether the current person may grant access to this account.
   */
  public function grant(UserInterface $user): AccessResultInterface {
    $allowed = $this->canManage() && (int) $user->id() > 0;
    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->addCacheableDependency($user);
  }

  /**
   * Whether this manual record may receive a later end.
   */
  public function extend(UserInterface $user, Entitlement $entitlement): AccessResultInterface {
    $allowed = $this->manualActive($user, $entitlement)
      && $entitlement->getEnds() !== NULL;
    return $this->result($allowed, $user, $entitlement);
  }

  /**
   * Whether this manual record may receive an earlier end.
   */
  public function shorten(UserInterface $user, Entitlement $entitlement): AccessResultInterface {
    $ends = $entitlement->getEnds();
    $allowed = $this->manualActive($user, $entitlement)
      && ($ends === NULL || $ends > $this->clock->now());
    return $this->result($allowed, $user, $entitlement);
  }

  /**
   * Whether this manual record may be revoked.
   */
  public function revoke(UserInterface $user, Entitlement $entitlement): AccessResultInterface {
    return $this->result($this->manualActive($user, $entitlement), $user, $entitlement);
  }

  /**
   * The current person holds the manual-access permission.
   */
  private function canManage(): bool {
    return $this->currentUser->hasPermission('manage lifebalance premium');
  }

  /**
   * The record belongs to this account and a manual change is allowed.
   */
  private function manualActive(UserInterface $user, Entitlement $entitlement): bool {
    return $this->canManage()
      && (int) $user->id() > 0
      && $entitlement->getOwnerId() === (int) $user->id()
      && $entitlement->isManual()
      && $entitlement->getStatus() === Entitlement::STATUS_ACTIVE;
  }

  /**
   * Access result cached per permission. Time can close a shorten form.
   */
  private function result(bool $allowed, UserInterface $user, Entitlement $entitlement): AccessResultInterface {
    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->setCacheMaxAge(0)
      ->addCacheableDependency($user)
      ->addCacheableDependency($entitlement);
  }

}
