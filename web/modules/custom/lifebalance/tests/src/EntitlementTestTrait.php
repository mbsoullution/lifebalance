<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance;

use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;

/**
 * Grants and revokes Premium through the entitlement manager.
 */
trait EntitlementTestTrait {

  /**
   * Grants Premium that is already active.
   */
  protected function grantEntitlement(AccountInterface $account, ?int $ends = NULL): Entitlement {
    $clock = $this->container->get(Clock::class);
    $manager = $this->container->get(EntitlementManager::class);
    return $manager->grant(
      $account,
      Entitlement::SOURCE_ADMIN,
      $clock->now() - 10,
      $ends,
      'Тестове надання',
      $account,
      FALSE,
    );
  }

  /**
   * Revokes every record that is active right now.
   */
  protected function revokeEntitlements(AccountInterface $account): void {
    $clock = $this->container->get(Clock::class);
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $manager = $this->container->get(EntitlementManager::class);
    foreach ($checker->getActive($account, $clock->now()) as $entitlement) {
      $manager->revoke($entitlement, 'Тестове скасування', $account);
    }
  }

}
