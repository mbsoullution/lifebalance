<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Entity;

use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Access\SectorAccessControlHandler;

/**
 * Life area sector. The machine ID is permanent.
 */
#[ConfigEntityType(
  id: 'lifebalance_sector',
  label: new TranslatableMarkup('Сектор'),
  label_collection: new TranslatableMarkup('Сектори'),
  label_singular: new TranslatableMarkup('сектор'),
  label_plural: new TranslatableMarkup('сектори'),
  config_prefix: 'sector',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
  ],
  handlers: [
    'access' => SectorAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance sectors',
  label_count: [
    'singular' => '@count сектор',
    'plural' => '@count секторів',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'weight',
    'color',
    'scale_low',
    'scale_high',
  ],
)]
class Sector extends ConfigEntityBase {

  /**
   * Hint shown next to the sector.
   */
  protected string $description = '';

  /**
   * Sort order. Lower values come first.
   */
  protected int $weight = 0;

  /**
   * Display color as #RRGGBB.
   */
  protected string $color = '#000000';

  /**
   * Optional caption for score 0. Empty until the owner writes it.
   */
  protected string $scale_low = '';

  /**
   * Optional caption for score 10. Empty until the owner writes it.
   */
  protected string $scale_high = '';

  /**
   * Returns the sector hint.
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * Returns the sort weight.
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Returns the #RRGGBB color.
   */
  public function getColor(): string {
    return $this->color;
  }

  /**
   * Returns the optional caption for score 0.
   */
  public function getScaleLow(): string {
    return $this->scale_low;
  }

  /**
   * Returns the optional caption for score 10.
   */
  public function getScaleHigh(): string {
    return $this->scale_high;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $id = $this->id();
    if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,32}$/', $id)) {
      throw new ConfigException('Sector ID must be a machine name of at most 32 characters.');
    }
    if (!$this->isNew() && !$this->isSyncing() && $this->getOriginalId() !== $id) {
      throw new ConfigException('Sector ID cannot be changed.');
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $this->color)) {
      throw new ConfigException('Sector color must be a #RRGGBB value.');
    }
    $this->scale_low = trim($this->scale_low);
    $this->scale_high = trim($this->scale_high);
    if (mb_strlen($this->scale_low) > 255 || mb_strlen($this->scale_high) > 255) {
      throw new ConfigException('Scale captions must be at most 255 characters.');
    }
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    foreach ($entities as $entity) {
      if (!$entity instanceof ConfigEntityInterface) {
        throw new ConfigException('Sectors cannot be deleted. Disable the sector instead.');
      }
      if (!$entity->isUninstalling() && !$entity->isSyncing()) {
        throw new ConfigException('Sectors cannot be deleted. Disable the sector instead.');
      }
    }
    parent::preDelete($storage, $entities);
  }

}
