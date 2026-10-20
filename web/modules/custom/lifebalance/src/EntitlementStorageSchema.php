<?php

declare(strict_types=1);

namespace Drupal\lifebalance;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Index entitlements by owner, status, and end time.
 */
class EntitlementStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Schema arrays keyed by table name.
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();
    if (isset($schema[$base_table])) {
      $schema[$base_table]['indexes']['entitlement__uid_status_ends'] = [
        'uid',
        'status',
        'ends',
      ];
    }
    return $schema;
  }

}
