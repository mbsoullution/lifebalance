<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Index goals by owner and status.
 */
class GoalStorageSchema extends SqlContentEntityStorageSchema {

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
      $schema[$base_table]['indexes']['goal__uid_status'] = [
        'uid',
        'status',
      ];
    }
    return $schema;
  }

}
