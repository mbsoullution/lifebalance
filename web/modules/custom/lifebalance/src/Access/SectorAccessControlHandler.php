<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for sectors. Deletion is never allowed.
 */
final class SectorAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return match ($operation) {
      'delete' => AccessResult::forbidden('Sectors cannot be deleted.')->addCacheContexts(['user.permissions']),
      'view', 'view label' => AccessResult::allowed()->addCacheContexts(['user.permissions']),
      'update' => AccessResult::allowedIfHasPermission($account, 'administer lifebalance sectors'),
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
    return AccessResult::allowedIfHasPermission($account, 'administer lifebalance sectors');
  }

}
