<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Removes goals when an account is deleted.
 */
final class GoalUserHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Deletes every goal and plan of this uid.
   */
  #[Hook('user_predelete')]
  public function predelete(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return;
    }
    $this->deleteOwned('lifebalance_goal', $uid);
    $this->deleteOwned('lifebalance_plan', $uid);
    $this->loggerFactory->get('lifebalance_plans')->info('Removed goals and plans for uid @uid.', [
      '@uid' => $uid,
    ]);
  }

  /**
   * Deletes entities of one type owned by this uid.
   */
  private function deleteOwned(string $entity_type, int $uid): void {
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    if ($ids !== []) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

}
