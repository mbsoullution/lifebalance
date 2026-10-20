<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Access for entitlements. Revisions and the record itself stay put.
 *
 * The record is removed only when the account is deleted, through storage
 * rather than this handler. There is no collection route.
 */
final class EntitlementAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if (!$entity instanceof Entitlement) {
      return AccessResult::neutral();
    }
    $owner = AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())
      ->cachePerUser()
      ->addCacheableDependency($entity);
    return match ($operation) {
      'view', 'view label', 'view revision' => $owner,
      'update', 'delete', 'delete revision' => AccessResult::forbidden(
        'Entitlement records are changed only through the entitlement manager.',
      )->addCacheContexts(['user.permissions']),
      default => AccessResult::neutral(),
    };
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
    return AccessResult::forbidden('Entitlement records are created only through the entitlement manager.')
      ->addCacheContexts(['user.permissions']);
  }

}
