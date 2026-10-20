<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance_plans\Access\PlanAccessControlHandler;
use Drupal\lifebalance_plans\PlanStorageSchema;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * One 30-day plan. At most one active plan belongs to a person.
 *
 * Finishing the plan does not delete its goals and does not change scores.
 */
#[ContentEntityType(
  id: 'lifebalance_plan',
  label: new TranslatableMarkup('План'),
  label_collection: new TranslatableMarkup('Плани'),
  label_singular: new TranslatableMarkup('план'),
  label_plural: new TranslatableMarkup('плани'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => PlanStorageSchema::class,
    'access' => PlanAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance plans',
  base_table: 'lifebalance_plan',
  translatable: FALSE,
)]
class Plan extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const STATUS_ACTIVE = 'active';

  public const STATUS_COMPLETED = 'completed';

  public const STATUS_ABANDONED = 'abandoned';

  public const DAYS = 30;

  /**
   * Set when cron closes a plan. The owner is not the current user then.
   */
  public bool $appliedBySystem = FALSE;

  /**
   * Status labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_ACTIVE => new TranslatableMarkup('Триває'),
      self::STATUS_COMPLETED => new TranslatableMarkup('Завершено'),
      self::STATUS_ABANDONED => new TranslatableMarkup('Завершено достроково'),
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
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $owner = $fields['uid'] ?? NULL;
    if ($owner instanceof BaseFieldDefinition) {
      $owner
        ->setLabel(new TranslatableMarkup('Власник'))
        ->setRequired(TRUE);
    }

    $fields['focus_sectors'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Сфери фокусу'))
      ->setCardinality(2)
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['starts'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Початок'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['ends'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Завершення'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Статус'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_ACTIVE)
      ->setSetting('allowed_values', self::statusLabels());

    $fields['invite_open'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Запрошення на самооцінку'))
      ->setDefaultValue(FALSE);

    $fields['completion_mailed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Лист про завершення надіслано'))
      ->setDefaultValue(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Створено'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Змінено'));

    return $fields;
  }

  /**
   * Chosen sector IDs, in stored order.
   *
   * @return list<string>
   *   One or two sector IDs.
   */
  public function getFocusSectors(): array {
    $ids = [];
    $stored = $this->get('focus_sectors')->getValue();
    foreach ($stored as $item) {
      $value = is_array($item) ? ($item['value'] ?? '') : '';
      if (is_string($value) && $value !== '') {
        $ids[] = $value;
      }
    }
    return $ids;
  }

  /**
   * UTC timestamp when the plan started.
   */
  public function getStarts(): int {
    return $this->stamp('starts');
  }

  /**
   * UTC timestamp of 23:59:59 Europe/Kyiv on the 30th day.
   */
  public function getEnds(): int {
    return $this->stamp('ends');
  }

  /**
   * Stored status.
   */
  public function getStatus(): string {
    $value = $this->get('status')->value;
    return is_string($value) && $value !== '' ? $value : self::STATUS_ACTIVE;
  }

  /**
   * Whether the site should invite a new self-assessment.
   */
  public function inviteOpen(): bool {
    return (int) $this->get('invite_open')->value === 1;
  }

  /**
   * Whether the completion letter was already handled.
   */
  public function completionMailed(): bool {
    return (int) $this->get('completion_mailed')->value === 1;
  }

  /**
   * Positive timestamp, or 0.
   */
  private function stamp(string $name): int {
    $value = $this->get($name)->value;
    return is_numeric($value) ? (int) $value : 0;
  }

}
