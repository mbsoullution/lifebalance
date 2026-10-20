<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance_billing\Access\PaymentOrderAccessControlHandler;
use Drupal\lifebalance_billing\PaymentOrderStorageSchema;

/**
 * One purchase. The price is a snapshot and is never read back from the plan.
 *
 * The record stays after the account is deleted. uid becomes NULL.
 */
#[ContentEntityType(
  id: 'lifebalance_payment_order',
  label: new TranslatableMarkup('Замовлення'),
  label_collection: new TranslatableMarkup('Замовлення'),
  label_singular: new TranslatableMarkup('замовлення'),
  label_plural: new TranslatableMarkup('замовлення'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'plan_label',
  ],
  handlers: [
    'storage_schema' => PaymentOrderStorageSchema::class,
    'access' => PaymentOrderAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance orders',
  base_table: 'lifebalance_payment_order',
  translatable: FALSE,
)]
final class PaymentOrder extends ContentEntityBase {

  public const STATUS_DRAFT = 'draft';

  public const STATUS_PENDING = 'pending';

  public const STATUS_PAID = 'paid';

  public const STATUS_FAILED = 'failed';

  public const STATUS_CANCELLED = 'cancelled';

  public const STATUS_EXPIRED = 'expired';

  public const STATUS_REFUNDED = 'refunded';

  public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

  public const STATUS_CHARGEBACK = 'chargeback';

  /**
   * Stored statuses.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_DRAFT => new TranslatableMarkup('Чернетка'),
      self::STATUS_PENDING => new TranslatableMarkup('Очікує'),
      self::STATUS_PAID => new TranslatableMarkup('Оплачено'),
      self::STATUS_FAILED => new TranslatableMarkup('Не пройшло'),
      self::STATUS_CANCELLED => new TranslatableMarkup('Скасовано'),
      self::STATUS_EXPIRED => new TranslatableMarkup('Прострочено'),
      self::STATUS_REFUNDED => new TranslatableMarkup('Повернено'),
      self::STATUS_PARTIALLY_REFUNDED => new TranslatableMarkup('Частково повернено'),
      self::STATUS_CHARGEBACK => new TranslatableMarkup('Спір'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Base field definitions.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Власник'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(FALSE);

    $fields['plan_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('План'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['plan_label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Назва плану'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['duration_days'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Тривалість'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['amount_minor'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Сума'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['list_price_minor'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Ціна без акції'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['promo_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Акція'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 32)
      ->setDefaultValue('');

    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Валюта'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 3);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Статус'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_DRAFT)
      ->setSetting('allowed_values', self::statusLabels());

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Провайдер'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['provider_invoice_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Рахунок провайдера'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField', []);

    $fields['provider_modified_at'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Час події провайдера'))
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE);

    $fields['entitlement_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Доступ'))
      ->setSetting('unsigned', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Створено'));

    $fields['paid_at'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Оплачено'))
      ->setSetting('unsigned', TRUE);

    $fields['refunded_at'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Повернено'))
      ->setSetting('unsigned', TRUE);

    return $fields;
  }

  /**
   * Account id, or 0 after the account was deleted.
   */
  public function getOwnerId(): int {
    $value = $this->get('uid')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Plan id copied at checkout.
   */
  public function getPlanId(): string {
    return (string) $this->get('plan_id')->value;
  }

  /**
   * Days copied at checkout.
   */
  public function getDurationDays(): int {
    return (int) $this->get('duration_days')->value;
  }

  /**
   * Amount copied at checkout, in minor units.
   */
  public function getAmountMinor(): int {
    return (int) $this->get('amount_minor')->value;
  }

  /**
   * Catalog price copied at checkout, before any later discount.
   */
  public function getListPriceMinor(): int {
    return (int) $this->get('list_price_minor')->value;
  }

  /**
   * Promo id copied at checkout, or an empty string.
   */
  public function getPromoId(): string {
    return (string) $this->get('promo_id')->value;
  }

  /**
   * Currency copied at checkout.
   */
  public function getCurrency(): string {
    return (string) $this->get('currency')->value;
  }

  /**
   * Stored status.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * Provider invoice id, or an empty string before checkout finishes.
   */
  public function getInvoiceId(): string {
    return (string) $this->get('provider_invoice_id')->value;
  }

  /**
   * Newest provider event time already applied.
   */
  public function getProviderModifiedAt(): int {
    return (int) $this->get('provider_modified_at')->value;
  }

  /**
   * Entitlement created for this order, or 0.
   */
  public function getEntitlementId(): int {
    $value = $this->get('entitlement_id')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Creation time.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

}
