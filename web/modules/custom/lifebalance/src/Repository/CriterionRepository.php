<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance\CriterionStorage;
use Drupal\lifebalance\Entity\Criterion;

/**
 * Loads criteria and their revisions.
 */
final class CriterionRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Published criteria of one sector, optionally one tier, by weight.
   *
   * @return list<\Drupal\lifebalance\Entity\Criterion>
   *   Matching criteria.
   */
  public function loadPublished(string $sector, ?string $tier = NULL): array {
    $storage = $this->storage();
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('sector', $sector)
      ->condition('status', Criterion::STATUS_PUBLISHED)
      ->sort('weight')
      ->sort('key');
    if ($tier !== NULL && $tier !== '') {
      $query->condition('tier', $tier);
    }
    return $this->loadMultiple($storage, $query->execute());
  }

  /**
   * One criterion by its stable catalog key.
   */
  public function loadByKey(string $key): ?Criterion {
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $key)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $entity = $storage->load($id);
    return $entity instanceof Criterion ? $entity : NULL;
  }

  /**
   * Criteria matching the admin filters. Empty filters are ignored.
   *
   * @return list<\Drupal\lifebalance\Entity\Criterion>
   *   Matching criteria, ordered by weight then key.
   */
  public function loadByFilters(string $sector = '', string $tier = '', string $status = ''): array {
    $storage = $this->storage();
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight')
      ->sort('key');
    if ($sector !== '') {
      $query->condition('sector', $sector);
    }
    if ($tier !== '') {
      $query->condition('tier', $tier);
    }
    if ($status !== '') {
      $query->condition('status', $status);
    }
    return $this->loadMultiple($storage, $query->execute());
  }

  /**
   * Revision IDs of a criterion, oldest first.
   *
   * @return list<int>
   *   Revision IDs.
   */
  public function revisionIds(Criterion $criterion): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->allRevisions()
      ->condition('id', $criterion->id())
      ->sort('revision_id')
      ->execute();
    $revision_ids = [];
    foreach (array_keys($ids) as $revision_id) {
      $revision_ids[] = (int) $revision_id;
    }
    sort($revision_ids);
    return $revision_ids;
  }

  /**
   * Criteria keyed by ID. Unknown IDs are omitted.
   *
   * @param list<int> $ids
   *   Criterion IDs.
   *
   * @return array<int, \Drupal\lifebalance\Entity\Criterion>
   *   Criteria keyed by ID.
   */
  public function loadByIds(array $ids): array {
    $ids = array_values(array_unique(array_filter(
      array_map(static fn (int $id): int => $id, $ids),
      static fn (int $id): bool => $id > 0,
    )));
    if ($ids === []) {
      return [];
    }
    $storage = $this->storage();
    $found = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('id', $ids, 'IN')
      ->execute();
    $criteria = [];
    foreach ($storage->loadMultiple($found) as $entity) {
      if ($entity instanceof Criterion) {
        $criteria[(int) $entity->id()] = $entity;
      }
    }
    return $criteria;
  }

  /**
   * Loads one revision.
   */
  public function loadRevision(int $revision_id): ?Criterion {
    $revision = $this->storage()->loadRevision($revision_id);
    return $revision instanceof Criterion ? $revision : NULL;
  }

  /**
   * Loads criteria for the given IDs.
   *
   * @param \Drupal\lifebalance\CriterionStorage $storage
   *   Criterion storage.
   * @param array<int|string, int|string> $ids
   *   Entity IDs from a query.
   *
   * @return list<\Drupal\lifebalance\Entity\Criterion>
   *   Loaded criteria.
   */
  private function loadMultiple(CriterionStorage $storage, array $ids): array {
    $criteria = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof Criterion) {
        $criteria[] = $entity;
      }
    }
    return $criteria;
  }

  /**
   * Criterion storage.
   */
  private function storage(): CriterionStorage {
    $storage = $this->entityTypeManager->getStorage('lifebalance_criterion');
    if (!$storage instanceof CriterionStorage) {
      throw new \LogicException('Criterion storage is not available.');
    }
    return $storage;
  }

}
