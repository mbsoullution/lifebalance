<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Access\EntitlementAccessControlHandler;
use Drupal\lifebalance\EntitlementStorage;
use Drupal\lifebalance\EntitlementStorageSchema;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * One grant of Premium. Expired is calculated, never stored.
 *
 * The record is not revisionable. Changes are appended to the entitlement
 * log. The record itself is deleted only together with the account.
 */
#[ContentEntityType(
  id: 'lifebalance_entitlement',
  label: new TranslatableMarkup('Доступ'),
  label_collection: new TranslatableMarkup('Доступи'),
  label_singular: new TranslatableMarkup('доступ'),
  label_plural: new TranslatableMarkup('доступи'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage' => EntitlementStorage::class,
    'storage_schema' => EntitlementStorageSchema::class,
    'access' => EntitlementAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance entitlements',
  base_table: 'lifebalance_entitlement',
  translatable: FALSE,
)]
class Entitlement extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const FEATURE_PREMIUM = 'premium';

  public const SOURCE_PAYMENT = 'payment';

  public const SOURCE_ADMIN = 'admin_grant';

  public const SOURCE_TRIAL = 'trial';

  public const SOURCE_PROMO = 'promo';

  public const STATUS_ACTIVE = 'active';

  public const STATUS_REVOKED = 'revoked';

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Base field definitions.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $owner = $fields['uid'] ?? NULL;
    if ($owner instanceof BaseFieldDefinition) {
      $owner
        ->setLabel(new TranslatableMarkup('Користувач'))
        ->setDescription(new TranslatableMarkup('Людина, якій надано доступ.'))
        ->setRequired(TRUE);
    }

    $fields['feature_set'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Набір функцій'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::FEATURE_PREMIUM)
      ->setSetting('max_length', 32);

    $fields['source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Джерело'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::SOURCE_PAYMENT => 'payment',
        self::SOURCE_ADMIN => 'admin_grant',
        self::SOURCE_TRIAL => 'trial',
        self::SOURCE_PROMO => 'promo',
      ]);

    $fields['starts'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Початок'))
      ->setDescription(new TranslatableMarkup('Початок доступу в UTC.'))
      ->setRequired(TRUE);

    $fields['ends'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Завершення'))
      ->setDescription(new TranslatableMarkup('Кінець доступу в UTC. Порожнє значення означає безстроково.'))
      ->setRequired(FALSE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Стан'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_ACTIVE)
      ->setSetting('allowed_values', [
        self::STATUS_ACTIVE => 'active',
        self::STATUS_REVOKED => 'revoked',
      ]);

    $fields['reason'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Причина'))
      ->setSetting('max_length', 255);

    $fields['granted_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Надав'))
      ->setSetting('target_type', 'user')
      ->setRequired(FALSE);

    $fields['order_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Замовлення'))
      ->setRequired(FALSE)
      ->addConstraint('UniqueField', []);

    $fields['notify_user'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Повідомити'))
      ->setDefaultValue(FALSE);

    $fields['expiry_notified'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Повідомлення про завершення надіслано'))
      ->setDefaultValue(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Створено'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Змінено'));

    return $fields;
  }

  /**
   * Stored feature set.
   */
  public function getFeatureSet(): string {
    return (string) $this->get('feature_set')->value;
  }

  /**
   * Stored source.
   */
  public function getSource(): string {
    return (string) $this->get('source')->value;
  }

  /**
   * Start instant.
   */
  public function getStarts(): int {
    return (int) $this->get('starts')->value;
  }

  /**
   * End instant, or NULL when the record does not expire.
   */
  public function getEnds(): ?int {
    $value = $this->get('ends')->value;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return (int) $value;
  }

  /**
   * Stored status. Expired is not a stored value.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * Reason stored on the record.
   */
  public function getReason(): string {
    return (string) $this->get('reason')->value;
  }

  /**
   * Payment order id, or NULL for a manual record.
   */
  public function getOrderId(): ?int {
    $value = $this->get('order_id')->value;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return (int) $value;
  }

  /**
   * Whether the person should be told about this grant.
   */
  public function notifyUser(): bool {
    return (int) $this->get('notify_user')->value === 1;
  }

  /**
   * Whether a manual operation may change this record.
   */
  public function isManual(): bool {
    return in_array($this->getSource(), [self::SOURCE_ADMIN, self::SOURCE_TRIAL], TRUE);
  }

}
