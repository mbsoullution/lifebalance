<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * View is allowed only for the owner. The admin permission is not a bypass.
 *
 * Updates happen in the payment processor, which uses storage directly.
 */
final class PaymentOrderAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $is_owner = $entity instanceof PaymentOrder
      && (int) $account->id() > 0
      && $entity->getOwnerId() === (int) $account->id();
    return AccessResult::allowedIf($operation === 'view' && $is_owner)
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   * @param array<string, mixed> $context
   *   Context for the create access check.
   * @param mixed $entity_bundle
   *   The entity bundle, if the entity type supports bundles.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::forbidden()->cachePerPermissions();
  }

}
