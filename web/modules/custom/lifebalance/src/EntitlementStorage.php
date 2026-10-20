<?php

declare(strict_types=1);

namespace Drupal\lifebalance;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Stores entitlements. A single revision cannot be removed.
 */
class EntitlementStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function deleteRevision($revision_id): never {
    throw new EntityStorageException('Entitlement revisions cannot be deleted.');
  }

}
