<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance_plans\Access\RecommendationAccessControlHandler;
use Drupal\lifebalance_plans\Form\RecommendationForm;
use Drupal\lifebalance_plans\RecommendationListBuilder;
use Drupal\lifebalance_plans\RecommendationStorage;
use Drupal\lifebalance_plans\RecommendationStorageSchema;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * An editorial recommendation template. It is not a person's goal.
 *
 * Title, body, and steps are revisionable. A change of any of them forces a
 * new revision. Recommendations are archived, never deleted.
 */
#[ContentEntityType(
  id: 'lifebalance_recommendation',
  label: new TranslatableMarkup('Рекомендація'),
  label_collection: new TranslatableMarkup('Рекомендації'),
  label_singular: new TranslatableMarkup('рекомендація'),
  label_plural: new TranslatableMarkup('рекомендації'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'title',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage' => RecommendationStorage::class,
    'storage_schema' => RecommendationStorageSchema::class,
    'access' => RecommendationAccessControlHandler::class,
    'list_builder' => RecommendationListBuilder::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => RecommendationForm::class,
      'edit' => RecommendationForm::class,
    ],
  ],
  links: [
    'canonical' => '/admin/lifebalance/recommendations/{lifebalance_recommendation}/edit',
    'edit-form' => '/admin/lifebalance/recommendations/{lifebalance_recommendation}/edit',
    'add-form' => '/admin/lifebalance/recommendations/add',
  ],
  admin_permission: 'administer lifebalance recommendations',
  base_table: 'lifebalance_recommendation',
  revision_table: 'lifebalance_recommendation_revision',
  translatable: FALSE,
  show_revision_ui: FALSE,
  constraints: [
    'Recommendation' => [],
  ],
  label_count: [
    'singular' => '@count рекомендація',
    'plural' => '@count рекомендацій',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class Recommendation extends RevisionableContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATUS_DRAFT = 'draft';

  public const STATUS_REVIEW = 'review';

  public const STATUS_PUBLISHED = 'published';

  public const STATUS_ARCHIVED = 'archived';

  public const TYPE_REFLECTION = 'reflection';

  public const TYPE_ACTION = 'action';

  public const TYPE_HABIT = 'habit';

  public const TYPE_RESOURCE = 'resource';

  public const EFFORT_SMALL = 'small';

  public const EFFORT_MEDIUM = 'medium';

  public const SAFETY_NONE = 'none';

  public const SAFETY_HEALTH = 'health';

  public const SAFETY_SENSITIVE = 'sensitive';

  public const TIER_PREMIUM = 'premium';

  public const TIER_FREE_PREVIEW = 'free_preview';

  public const ANSWER_NO = 'no';

  public const ANSWER_PARTIAL = 'partial';

  /**
   * Plain-text format. Images and scripts are not stored.
   */
  public const BODY_FORMAT = 'plain_text';

  /**
   * Status labels for the editorial workflow.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_DRAFT => new TranslatableMarkup('Чернетка'),
      self::STATUS_REVIEW => new TranslatableMarkup('На перевірці'),
      self::STATUS_PUBLISHED => new TranslatableMarkup('Опубліковано'),
      self::STATUS_ARCHIVED => new TranslatableMarkup('Архів'),
    ];
  }

  /**
   * Recommendation type labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function typeLabels(): array {
    return [
      self::TYPE_REFLECTION => new TranslatableMarkup('Рефлексія'),
      self::TYPE_ACTION => new TranslatableMarkup('Дія'),
      self::TYPE_HABIT => new TranslatableMarkup('Звичка'),
      self::TYPE_RESOURCE => new TranslatableMarkup('Джерело'),
    ];
  }

  /**
   * Effort labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function effortLabels(): array {
    return [
      self::EFFORT_SMALL => new TranslatableMarkup('Невелике'),
      self::EFFORT_MEDIUM => new TranslatableMarkup('Помірне'),
    ];
  }

  /**
   * Safety labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function safetyLabels(): array {
    return [
      self::SAFETY_NONE => new TranslatableMarkup('Звичайна'),
      self::SAFETY_HEALTH => new TranslatableMarkup('Здоровʼя'),
      self::SAFETY_SENSITIVE => new TranslatableMarkup('Чутлива'),
    ];
  }

  /**
   * Tier labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function tierLabels(): array {
    return [
      self::TIER_PREMIUM => new TranslatableMarkup('Розширене'),
      self::TIER_FREE_PREVIEW => new TranslatableMarkup('Демо'),
    ];
  }

  /**
   * Answer labels that can trigger a recommendation.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function answerLabels(): array {
    return [
      self::ANSWER_NO => new TranslatableMarkup('Ні'),
      self::ANSWER_PARTIAL => new TranslatableMarkup('Частково'),
    ];
  }

  /**
   * Statuses that require the publish permission.
   *
   * @return list<string>
   *   Stored status values.
   */
  public static function publishingStatuses(): array {
    return [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];
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
        ->setLabel(new TranslatableMarkup('Автор'))
        ->setDescription(new TranslatableMarkup('Хто додав рекомендацію.'));
    }

    $fields['key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Ключ'))
      ->setDescription(new TranslatableMarkup('Стабільний ключ. Після створення не змінюється.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField', [])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ]);

    $fields['sector'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Сектор'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ]);

    $fields['trigger_criteria'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Критерії'))
      ->setDescription(new TranslatableMarkup('Ключі критеріїв цієї сфери.'))
      ->setRequired(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 20,
      ]);

    $fields['trigger_answers'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Відповіді'))
      ->setDescription(new TranslatableMarkup('Рекомендація розглядається, якщо відповідь збігається.'))
      ->setRequired(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('allowed_values', self::answerLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 30,
      ]);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Назва'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 80)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 40,
      ]);

    $fields['body'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Текст'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_formats', [self::BODY_FORMAT])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 50,
        'settings' => [
          'rows' => 4,
        ],
      ]);

    $fields['steps'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Кроки'))
      ->setDescription(new TranslatableMarkup('Від одного до пʼяти коротких кроків.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 60,
      ]);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Тип'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::typeLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 70,
      ]);

    $fields['effort'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Зусилля'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::effortLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 80,
      ]);

    $fields['safety'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Безпека'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::safetyLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 90,
      ]);

    $fields['tier'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Рівень'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::tierLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 100,
      ]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Статус'))
      ->setDescription(new TranslatableMarkup('Чернетка і перевірка — з правом редагування. Публікація і архів — з правом публікації.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_DRAFT)
      ->setSetting('allowed_values', self::statusLabels())
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 110,
      ]);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Порядок'))
      ->setDescription(new TranslatableMarkup('Менше значення показується раніше.'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 120,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Змінено'))
      ->setDescription(new TranslatableMarkup('Час останньої зміни.'));

    if ($entity_type instanceof ContentEntityTypeInterface) {
      $log_key = $entity_type->getRevisionMetadataKey('revision_log_message');
      if (is_string($log_key) && isset($fields[$log_key]) && $fields[$log_key] instanceof BaseFieldDefinition) {
        $fields[$log_key]->setDisplayOptions('form', [
          'region' => 'hidden',
        ]);
      }
    }

    return $fields;
  }

  /**
   * Stable catalog key.
   */
  public function getCatalogKey(): string {
    return $this->plain('key');
  }

  /**
   * Sector machine ID.
   */
  public function getSectorId(): string {
    return $this->plain('sector');
  }

  /**
   * Stored editorial status.
   */
  public function getStatus(): string {
    return $this->plain('status');
  }

  /**
   * Short title, at most 80 characters.
   */
  public function getTitle(): string {
    return $this->plain('title');
  }

  /**
   * Recommendation text without the text format.
   */
  public function getBody(): string {
    return $this->plain('body');
  }

  /**
   * Stored type value.
   */
  public function getType(): string {
    return $this->plain('type');
  }

  /**
   * Stored effort value.
   */
  public function getEffort(): string {
    return $this->plain('effort');
  }

  /**
   * Stored safety value.
   */
  public function getSafety(): string {
    return $this->plain('safety');
  }

  /**
   * Stored tier value.
   */
  public function getTier(): string {
    return $this->plain('tier');
  }

  /**
   * Criterion keys that can trigger this recommendation.
   *
   * @return list<string>
   *   Catalog keys.
   */
  public function getTriggerCriteria(): array {
    return $this->lines('trigger_criteria');
  }

  /**
   * Answers that can trigger this recommendation.
   *
   * @return list<string>
   *   Stored answer values.
   */
  public function getTriggerAnswers(): array {
    return $this->lines('trigger_answers');
  }

  /**
   * Checklist steps copied into a goal later.
   *
   * @return list<string>
   *   Step texts.
   */
  public function getSteps(): array {
    return $this->lines('steps');
  }

  /**
   * Sort weight inside the same trigger.
   */
  public function getWeight(): int {
    $value = $this->get('weight')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    if ($this->get('body')->format !== self::BODY_FORMAT) {
      $this->set('body', [
        'value' => $this->getBody(),
        'format' => self::BODY_FORMAT,
      ]);
    }
    if (!$this->isNew()) {
      $original = $this->getOriginal();
      if (!$original instanceof self) {
        throw new EntityStorageException('Cannot update a recommendation without its previous values.');
      }
      if ($original->getCatalogKey() !== $this->getCatalogKey()) {
        throw new EntityStorageException('Recommendation key cannot be changed.');
      }
      $wording_changed = $original->getTitle() !== $this->getTitle()
        || $original->getBody() !== $this->getBody()
        || $original->getSteps() !== $this->getSteps();
      if ($wording_changed) {
        $this->setNewRevision(TRUE);
      }
    }
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage.
   * @param array<int|string, \Drupal\lifebalance_plans\Entity\Recommendation> $entities
   *   Recommendations that would be deleted.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    throw new EntityStorageException('Recommendations cannot be deleted. Archive the recommendation instead.');
  }

  /**
   * Whether this status needs the publish permission.
   */
  public function isPublishingStatus(): bool {
    return in_array($this->getStatus(), self::publishingStatuses(), TRUE);
  }

  /**
   * Field value as text. NULL and non-scalars become an empty string.
   */
  private function plain(string $field): string {
    $value = $this->get($field)->value;
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Non-empty string items from a multi-value field.
   *
   * @return list<string>
   *   Item values in delta order.
   */
  private function lines(string $field): array {
    $lines = [];
    foreach ($this->get($field) as $item) {
      $value = $item->value ?? NULL;
      if (is_string($value) && $value !== '') {
        $lines[] = $value;
      }
    }
    return $lines;
  }

}
