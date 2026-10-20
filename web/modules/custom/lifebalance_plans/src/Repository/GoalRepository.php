<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Repository;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance_plans\Entity\Goal;

/**
 * Loads goals for one owner.
 *
 * Every query includes that uid. Personal routes must pass the current user.
 */
final class GoalRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Goals of one owner, newest first.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Goal>
   *   Matching goals.
   */
  public function loadForOwner(int $uid): array {
    $this->assertOwner($uid);
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    return $this->loadInOrder($storage, $ids, $uid);
  }

  /**
   * One goal of this owner, identified by UUID.
   */
  public function loadByUuidForOwner(string $uuid, int $uid): ?Goal {
    $this->assertOwner($uid);
    if ($uuid === '') {
      return NULL;
    }
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $entity = $storage->load($id);
    if (!$entity instanceof Goal || (int) $entity->getOwnerId() !== $uid) {
      return NULL;
    }
    return $entity;
  }

  /**
   * How many planned or in-progress goals this owner has.
   */
  public function countActive(int $uid): int {
    $this->assertOwner($uid);
    return (int) $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('status', [
        Goal::STATUS_PLANNED,
        Goal::STATUS_IN_PROGRESS,
      ], 'IN')
      ->count()
      ->execute();
  }

  /**
   * Active goals that already copy this recommendation.
   */
  public function countActiveForRecommendation(int $uid, int $recommendation_id): int {
    $this->assertOwner($uid);
    if ($recommendation_id < 1) {
      return 0;
    }
    return (int) $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('recommendation_id', $recommendation_id)
      ->condition('status', [
        Goal::STATUS_PLANNED,
        Goal::STATUS_IN_PROGRESS,
      ], 'IN')
      ->count()
      ->execute();
  }

  /**
   * Refuses a query that is not tied to one person.
   */
  private function assertOwner(int $uid): void {
    if ($uid < 1) {
      throw new \InvalidArgumentException('A goal query requires an owner.');
    }
  }

  /**
   * Goal storage.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lifebalance_goal');
  }

  /**
   * Loads ids in query order and drops rows of another owner.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Goal storage.
   * @param array<int|string> $ids
   *   Entity ids.
   * @param int $uid
   *   Owner to keep.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Goal>
   *   Goals.
   */
  private function loadInOrder(EntityStorageInterface $storage, array $ids, int $uid): array {
    if ($ids === []) {
      return [];
    }
    $loaded = $storage->loadMultiple($ids);
    $goals = [];
    foreach ($ids as $id) {
      $entity = $loaded[$id] ?? NULL;
      if ($entity instanceof Goal && (int) $entity->getOwnerId() === $uid) {
        $goals[] = $entity;
      }
    }
    return $goals;
  }

}
