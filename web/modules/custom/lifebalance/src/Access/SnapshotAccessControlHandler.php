<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Snapshot;

/**
 * View, update, and delete are allowed only for the owner.
 *
 * The admin permission does not grant access. site_manager and user 1 follow
 * the same rule. Deletion of another person's snapshots during account
 * removal goes through storage, which does not consult this handler.
 */
final class SnapshotAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $is_owner = $entity instanceof Snapshot
      && (int) $account->id() > 0
      && (int) $entity->getOwnerId() === (int) $account->id();
    $allowed = match ($operation) {
      'view', 'update', 'delete' => $is_owner,
      default => FALSE,
    };
    return AccessResult::allowedIf($allowed)
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
    return AccessResult::allowedIf($account->isAuthenticated())->cachePerUser();
  }

}
