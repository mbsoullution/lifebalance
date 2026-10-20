<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Repository;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance\Entity\Snapshot;

/**
 * Loads snapshots for one owner.
 *
 * Every query includes that uid. Personal routes must pass the current user.
 */
final class SnapshotRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Snapshots of one owner, newest first.
   *
   * @return list<\Drupal\lifebalance\Entity\Snapshot>
   *   Matching snapshots.
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
   * One snapshot of this owner, identified by UUID.
   */
  public function loadByUuidForOwner(string $uuid, int $uid): ?Snapshot {
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
    if (!$entity instanceof Snapshot || (int) $entity->getOwnerId() !== $uid) {
      return NULL;
    }
    return $entity;
  }

  /**
   * How many snapshots this owner has.
   */
  public function countForOwner(int $uid): int {
    $this->assertOwner($uid);
    return (int) $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->count()
      ->execute();
  }

  /**
   * One page of snapshots, newest first.
   *
   * @return list<\Drupal\lifebalance\Entity\Snapshot>
   *   Snapshots for the requested page.
   */
  public function loadPageForOwner(int $uid, int $page, int $limit = 20): array {
    $this->assertOwner($uid);
    $page = max(0, $page);
    $limit = max(1, $limit);
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range($page * $limit, $limit)
      ->execute();
    return $this->loadInOrder($storage, $ids, $uid);
  }

  /**
   * Newest snapshots, optionally since a UTC timestamp.
   *
   * @return list<\Drupal\lifebalance\Entity\Snapshot>
   *   At most $limit snapshots, newest first.
   */
  public function loadRecentForOwner(int $uid, int $limit, ?int $since = NULL): array {
    $this->assertOwner($uid);
    $limit = max(1, min(500, $limit));
    $storage = $this->storage();
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, $limit);
    if ($since !== NULL) {
      $query->condition('created', $since, '>=');
    }
    return $this->loadInOrder($storage, $query->execute(), $uid);
  }

  /**
   * Newest snapshot of this owner, or NULL when they have none.
   */
  public function loadLatestForOwner(int $uid): ?Snapshot {
    $this->assertOwner($uid);
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $snapshots = $this->loadInOrder($storage, $ids, $uid);
    return $snapshots[0] ?? NULL;
  }

  /**
   * Rejects a missing owner before any query runs.
   */
  private function assertOwner(int $uid): void {
    if ($uid < 1) {
      throw new \InvalidArgumentException('Snapshot queries require an owner id.');
    }
  }

  /**
   * Loads snapshots in query order and drops anything owned by someone else.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Snapshot storage.
   * @param array<int|string, int|string> $ids
   *   Entity IDs from a query, in the desired order.
   * @param int $uid
   *   Owner ID that every returned snapshot must match.
   *
   * @return list<\Drupal\lifebalance\Entity\Snapshot>
   *   Snapshots for this owner.
   */
  private function loadInOrder(EntityStorageInterface $storage, array $ids, int $uid): array {
    $loaded = $storage->loadMultiple($ids);
    $snapshots = [];
    foreach (array_keys($ids) as $id) {
      $entity = $loaded[$id] ?? NULL;
      if ($entity instanceof Snapshot && (int) $entity->getOwnerId() === $uid) {
        $snapshots[] = $entity;
      }
    }
    return $snapshots;
  }

  /**
   * Snapshot storage.
   */
  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('lifebalance_snapshot');
  }

}
