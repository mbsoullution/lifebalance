<?php

declare(strict_types=1);

namespace Drupal\lifebalance;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Stores criteria. Revisions are kept for the life of the catalog.
 */
class CriterionStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function deleteRevision($revision_id): never {
    throw new EntityStorageException('Criterion revisions cannot be deleted.');
  }

}
