<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance_plans\Access\GoalAccessControlHandler;
use Drupal\lifebalance_plans\GoalStorageSchema;
use Drupal\lifebalance_plans\Plugin\Field\FieldType\GoalStepItem;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A person's goal. Text is a copy and is not a recommendation.
 *
 * Completing a goal or a step never changes a snapshot.
 */
#[ContentEntityType(
  id: 'lifebalance_goal',
  label: new TranslatableMarkup('Ціль'),
  label_collection: new TranslatableMarkup('Цілі'),
  label_singular: new TranslatableMarkup('ціль'),
  label_plural: new TranslatableMarkup('цілі'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => GoalStorageSchema::class,
    'access' => GoalAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance goals',
  base_table: 'lifebalance_goal',
  translatable: FALSE,
)]
class Goal extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const ORIGIN_RECOMMENDATION = 'recommendation';

  public const ORIGIN_OWN = 'own';

  public const STATUS_PLANNED = 'planned';

  public const STATUS_IN_PROGRESS = 'in_progress';

  public const STATUS_DONE = 'done';

  public const STATUS_CANCELLED = 'cancelled';

  /**
   * Status labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_PLANNED => new TranslatableMarkup('Заплановано'),
      self::STATUS_IN_PROGRESS => new TranslatableMarkup('У процесі'),
      self::STATUS_DONE => new TranslatableMarkup('Виконано'),
      self::STATUS_CANCELLED => new TranslatableMarkup('Скасовано'),
    ];
  }

  /**
   * Origin labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function originLabels(): array {
    return [
      self::ORIGIN_RECOMMENDATION => new TranslatableMarkup('З підказки'),
      self::ORIGIN_OWN => new TranslatableMarkup('Власна'),
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
        ->setDescription(new TranslatableMarkup('Людина, якій належить ціль.'))
        ->setRequired(TRUE);
    }

    $fields['sector'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Сфера'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['origin'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Походження'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::originLabels());

    $fields['recommendation_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Рекомендація'))
      ->setSetting('unsigned', TRUE);

    $fields['recommendation_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Ревізія рекомендації'))
      ->setSetting('unsigned', TRUE);

    $fields['plan_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('План'))
      ->setSetting('unsigned', TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Назва'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 80);

    $fields['instructions'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Опис'))
      ->setRequired(TRUE);

    $fields['steps'] = BaseFieldDefinition::create('lifebalance_goal_step')
      ->setLabel(new TranslatableMarkup('Кроки'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRequired(TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Статус'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_PLANNED)
      ->setSetting('allowed_values', self::statusLabels());

    $fields['due'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Термін'))
      ->setSetting('max_length', 10);

    $fields['completed_at'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Виконано'))
      ->setSetting('unsigned', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Створено'))
      ->setDescription(new TranslatableMarkup('Час створення цілі в UTC.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Змінено'));

    return $fields;
  }

  /**
   * Sector machine ID.
   */
  public function getSectorId(): string {
    return $this->plain('sector');
  }

  /**
   * Stored origin: recommendation or own.
   */
  public function getOrigin(): string {
    return $this->plain('origin');
  }

  /**
   * Source recommendation, or NULL for a goal the person wrote.
   */
  public function getRecommendationId(): ?int {
    return $this->positive('recommendation_id');
  }

  /**
   * Recommendation revision copied at creation, or NULL.
   */
  public function getRecommendationRevisionId(): ?int {
    return $this->positive('recommendation_revision_id');
  }

  /**
   * Plan this goal was started with, or NULL.
   */
  public function getPlanId(): ?int {
    return $this->positive('plan_id');
  }

  /**
   * Copied title.
   */
  public function getTitle(): string {
    return $this->plain('title');
  }

  /**
   * Copied instructions.
   */
  public function getInstructions(): string {
    return $this->plain('instructions');
  }

  /**
   * Stored status.
   */
  public function getStatus(): string {
    $value = $this->plain('status');
    return $value !== '' ? $value : self::STATUS_PLANNED;
  }

  /**
   * Due date as Y-m-d, or NULL when the goal has no term.
   */
  public function getDue(): ?string {
    $value = $this->plain('due');
    return $value !== '' ? $value : NULL;
  }

  /**
   * When the goal was marked done, or NULL otherwise.
   */
  public function getCompletedAt(): ?int {
    $value = $this->get('completed_at')->value;
    if (!is_numeric($value)) {
      return NULL;
    }
    $time = (int) $value;
    return $time > 0 ? $time : NULL;
  }

  /**
   * Creation time as a UTC timestamp.
   */
  public function getCreatedTime(): int {
    $value = $this->get('created')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Planned or in progress.
   */
  public function isActive(): bool {
    return in_array($this->getStatus(), [
      self::STATUS_PLANNED,
      self::STATUS_IN_PROGRESS,
    ], TRUE);
  }

  /**
   * Checklist steps, skipping empty items.
   *
   * @return list<\Drupal\lifebalance_plans\Plugin\Field\FieldType\GoalStepItem>
   *   Step items.
   */
  public function getSteps(): array {
    $items = [];
    foreach ($this->get('steps') as $item) {
      if ($item instanceof GoalStepItem && !$item->isEmpty()) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Scalar string field, or an empty string.
   */
  private function plain(string $name): string {
    $value = $this->get($name)->value;
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Positive integer field, or NULL.
   */
  private function positive(string $name): ?int {
    $value = $this->get($name)->value;
    if (!is_numeric($value)) {
      return NULL;
    }
    $id = (int) $value;
    return $id > 0 ? $id : NULL;
  }

}
