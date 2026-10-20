<?php

declare(strict_types=1);

namespace Drupal\lifebalance;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Unique catalog key and the sector/tier/status/weight index.
 *
 * The column name "key" is reserved in SQL. The database layer quotes it.
 */
class CriterionStorageSchema extends SqlContentEntityStorageSchema {

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
      $schema[$base_table]['indexes']['criterion__sector_tier_status_weight'] = [
        'sector',
        'tier',
        'status',
        'weight',
      ];
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The field storage definition.
   * @param string $table_name
   *   The table that stores the field.
   * @param array<string, string> $column_mapping
   *   Field columns mapped to schema columns.
   *
   * @return array<string, mixed>
   *   Schema definition for the field.
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping): array {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($table_name === $this->storage->getBaseTable() && $storage_definition->getName() === 'key') {
      $this->addSharedTableFieldUniqueKey($storage_definition, $schema);
    }
    return $schema;
  }

}
