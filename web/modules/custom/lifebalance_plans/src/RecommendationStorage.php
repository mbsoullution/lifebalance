<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Stores recommendations. Revisions are kept for the life of the library.
 */
class RecommendationStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function deleteRevision($revision_id): never {
    throw new EntityStorageException('Recommendation revisions cannot be deleted.');
  }

}
