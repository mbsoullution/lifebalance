<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A one-time Premium plan. The price is copied onto each order.
 */
#[ConfigEntityType(
  id: 'lifebalance_billing_plan',
  label: new TranslatableMarkup('План оплати'),
  label_collection: new TranslatableMarkup('Плани оплати'),
  label_singular: new TranslatableMarkup('план оплати'),
  label_plural: new TranslatableMarkup('плани оплати'),
  config_prefix: 'plan',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
    'weight' => 'weight',
  ],
  admin_permission: 'administer lifebalance orders',
  config_export: [
    'id',
    'label',
    'duration_days',
    'price_minor',
    'currency',
    'weight',
  ],
)]
final class BillingPlan extends ConfigEntityBase {

  /**
   * How many days the purchase lasts.
   */
  protected int $duration_days = 30;

  /**
   * Price in minor currency units.
   */
  protected int $price_minor = 0;

  /**
   * ISO currency code. This release uses UAH.
   */
  protected string $currency = 'UAH';

  /**
   * Sort order. Lower values come first.
   */
  protected int $weight = 0;

  /**
   * Days of access this plan sells.
   */
  public function getDurationDays(): int {
    return $this->duration_days;
  }

  /**
   * Price in minor units at the moment this config is read.
   */
  public function getPriceMinor(): int {
    return $this->price_minor;
  }

  /**
   * Currency code stored on the plan.
   */
  public function getCurrency(): string {
    return $this->currency;
  }

  /**
   * {@inheritdoc}
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b): int {
    $left = $a instanceof self ? $a->weight : 0;
    $right = $b instanceof self ? $b->weight : 0;
    $weight = $left <=> $right;
    return $weight !== 0 ? $weight : strnatcasecmp($a->label(), $b->label());
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->duration_days < 1 || $this->price_minor < 1 || $this->currency !== 'UAH') {
      throw new \InvalidArgumentException('This billing plan is not sellable.');
    }
  }

}
