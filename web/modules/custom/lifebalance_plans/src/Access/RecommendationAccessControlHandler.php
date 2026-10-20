<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance_plans\Entity\Recommendation;

/**
 * Editorial access. These permissions never reveal a person's data.
 *
 * Deletion of a recommendation or a revision is impossible.
 */
final class RecommendationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $locked = $entity instanceof Recommendation && $entity->isPublishingStatus();
    return match ($operation) {
      'delete', 'delete revision' => AccessResult::forbidden('Recommendations and their revisions cannot be deleted.')
        ->addCacheContexts(['user.permissions']),
      'view', 'view label', 'view revision' => AccessResult::allowedIfHasPermissions($account, [
        'edit lifebalance recommendations',
        'publish lifebalance recommendations',
      ], 'OR'),
      'update' => $locked
        ? AccessResult::allowedIfHasPermission($account, 'publish lifebalance recommendations')
        : AccessResult::allowedIfHasPermissions($account, [
          'edit lifebalance recommendations',
          'publish lifebalance recommendations',
        ], 'OR'),
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
    return AccessResult::allowedIfHasPermission($account, 'edit lifebalance recommendations');
  }

}
