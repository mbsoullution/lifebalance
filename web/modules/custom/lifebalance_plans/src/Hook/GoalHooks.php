<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Service\GoalManager;

/**
 * Keeps goal writes owner-only and drops the owner's list cache.
 */
final class GoalHooks {

  public function __construct(
    private readonly GoalManager $goals,
    private readonly AccountProxyInterface $currentUser,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Rejects a goal saved by someone other than its owner.
   */
  #[Hook('lifebalance_goal_presave')]
  public function presave(Goal $goal): void {
    $uid = (int) $goal->getOwnerId();
    if ($uid < 1 || $uid !== (int) $this->currentUser->id()) {
      throw new EntityStorageException('A goal can be saved only by its owner.');
    }
    $this->goals->assertCreatable($goal);
    $violations = $goal->validate();
    if ($violations->count() === 0) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    throw new EntityStorageException(implode(' ', $messages));
  }

  /**
   * Invalidates the owner's goal list.
   */
  #[Hook('lifebalance_goal_insert')]
  #[Hook('lifebalance_goal_update')]
  #[Hook('lifebalance_goal_delete')]
  public function invalidateList(Goal $goal): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'lifebalance_goal_list:' . $goal->getOwnerId(),
    ]);
  }

}
