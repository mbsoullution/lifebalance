<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Unique invoice ids, and an index for the pending reconciliation window.
 */
class PaymentOrderStorageSchema extends SqlContentEntityStorageSchema {

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
      $schema[$base_table]['indexes']['payment_order__invoice'] = [
        'provider_invoice_id',
      ];
      $schema[$base_table]['indexes']['payment_order__status_created'] = [
        'status',
        'created',
      ];
    }
    return $schema;
  }

}
