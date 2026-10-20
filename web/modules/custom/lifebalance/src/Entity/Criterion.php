<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Access\CriterionAccessControlHandler;
use Drupal\lifebalance\CriterionStorage;
use Drupal\lifebalance\CriterionStorageSchema;
use Drupal\lifebalance\Form\CriterionForm;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A self-assessment statement. The catalog key never changes.
 *
 * Text and help are revisionable. Any change of either forces a new revision.
 * Subtopic is an optional UI grouping label and is not revisionable.
 * Criteria and their revisions are archived, never deleted.
 */
#[ContentEntityType(
  id: 'lifebalance_criterion',
  label: new TranslatableMarkup('Критерій'),
  label_collection: new TranslatableMarkup('Критерії'),
  label_singular: new TranslatableMarkup('критерій'),
  label_plural: new TranslatableMarkup('критерії'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'text',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage' => CriterionStorage::class,
    'storage_schema' => CriterionStorageSchema::class,
    'access' => CriterionAccessControlHandler::class,
    'form' => [
      'default' => CriterionForm::class,
    ],
  ],
  links: [
    'canonical' => '/admin/lifebalance/criteria/{lifebalance_criterion}/edit',
    'edit-form' => '/admin/lifebalance/criteria/{lifebalance_criterion}/edit',
    'add-form' => '/admin/lifebalance/criteria/add',
    'collection' => '/admin/lifebalance/criteria',
    'archive' => '/admin/lifebalance/criteria/{lifebalance_criterion}/archive',
    'revisions' => '/admin/lifebalance/criteria/{lifebalance_criterion}/revisions',
  ],
  admin_permission: 'administer lifebalance criteria',
  base_table: 'lifebalance_criterion',
  revision_table: 'lifebalance_criterion_revision',
  translatable: FALSE,
  show_revision_ui: FALSE,
  label_count: [
    'singular' => '@count критерій',
    'plural' => '@count критеріїв',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class Criterion extends RevisionableContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  public const STATUS_DRAFT = 'draft';

  public const STATUS_PUBLISHED = 'published';

  public const STATUS_ARCHIVED = 'archived';

  public const TIER_FREE = 'free';

  public const TIER_PREMIUM = 'premium';

  public const ORIGIN_SYSTEM = 'system';

  public const ORIGIN_MENTOR = 'mentor_proposed';

  public const ORIGIN_PERSONAL = 'personal';

  /**
   * Machine key: health.f01, later mentor.<uuid>.
   */
  public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

  /**
   * Sector IDs are machine names of at most 32 characters.
   */
  public const SECTOR_PATTERN = '/^[a-z0-9_]{1,32}$/';

  /**
   * Tier labels for the basic and extended catalogs.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function tierLabels(): array {
    return [
      self::TIER_FREE => new TranslatableMarkup('Базовий'),
      self::TIER_PREMIUM => new TranslatableMarkup('Розширений'),
    ];
  }

  /**
   * Status labels. Published criteria appear in the questionnaire.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_DRAFT => new TranslatableMarkup('Чернетка'),
      self::STATUS_PUBLISHED => new TranslatableMarkup('Опубліковано'),
      self::STATUS_ARCHIVED => new TranslatableMarkup('Архів'),
    ];
  }

  /**
   * Origin labels. Mentor and personal values are reserved for later.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function originLabels(): array {
    return [
      self::ORIGIN_SYSTEM => new TranslatableMarkup('Системний'),
      self::ORIGIN_MENTOR => new TranslatableMarkup('Пропозиція наставника'),
      self::ORIGIN_PERSONAL => new TranslatableMarkup('Персональний'),
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
        ->setLabel(new TranslatableMarkup('Автор'))
        ->setDescription(new TranslatableMarkup('Хто додав критерій.'));
    }

    $fields['key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Ключ'))
      ->setDescription(new TranslatableMarkup('Стабільний ключ. Після створення не змінюється.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
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

    $fields['tier'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Рівень'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', self::tierLabels())
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 20,
      ]);

    $fields['text'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Формулювання'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 30,
      ]);

    $fields['help'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Пояснення'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 40,
        'settings' => [
          'rows' => 3,
        ],
      ]);

    $fields['subtopic'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Підтема'))
      ->setDescription(new TranslatableMarkup(
        'Групування в інтерфейсі. Формула орієнтира його не використовує.',
      ))
      ->setRequired(FALSE)
      ->setRevisionable(FALSE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 35,
      ]);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Порядок'))
      ->setDescription(new TranslatableMarkup('Менше значення показується раніше.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 50,
      ]);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Статус'))
      ->setDescription(new TranslatableMarkup('В опитувальнику лише опубліковані критерії.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_PUBLISHED)
      ->setSetting('allowed_values', self::statusLabels())
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 60,
      ]);

    $fields['origin'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Походження'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::ORIGIN_SYSTEM)
      ->setSetting('allowed_values', self::originLabels())
      ->setSetting('max_length', 32);

    $fields['in_formula'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('У формулі'))
      ->setDescription(new TranslatableMarkup('Враховувати у формулі орієнтира.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 70,
        'settings' => [
          'display_label' => TRUE,
        ],
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
   * Catalog key, for example health.f01.
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
   * Stored tier: free or premium.
   */
  public function getTier(): string {
    return $this->plain('tier');
  }

  /**
   * Stored status: draft, published, or archived.
   */
  public function getStatus(): string {
    return $this->plain('status');
  }

  /**
   * Stored origin.
   */
  public function getOrigin(): string {
    return $this->plain('origin');
  }

  /**
   * Statement shown to the person filling the questionnaire.
   */
  public function getText(): string {
    return $this->plain('text');
  }

  /**
   * Optional explanation. Empty when none is stored.
   */
  public function getHelp(): string {
    return $this->plain('help');
  }

  /**
   * Optional UI grouping label. Empty when none is stored.
   */
  public function getSubtopic(): string {
    return $this->plain('subtopic');
  }

  /**
   * Sort weight inside the sector.
   */
  public function getWeight(): int {
    $value = $this->get('weight')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Whether this criterion is included in the guide formula.
   */
  public function isInFormula(): bool {
    return (bool) $this->get('in_formula')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $this->assertShape();
    if ($this->get('in_formula')->value === NULL) {
      $this->set('in_formula', TRUE);
    }
    $subtopic = trim($this->getSubtopic());
    if (mb_strlen($subtopic) > 128) {
      throw new EntityStorageException('Criterion subtopic must be at most 128 characters.');
    }
    $this->set('subtopic', $subtopic === '' ? NULL : $subtopic);

    if (!$this->isNew()) {
      $original = $this->getOriginal();
      if (!$original instanceof self) {
        throw new EntityStorageException('Cannot update a criterion without its previous values.');
      }
      if ($original->getCatalogKey() !== $this->getCatalogKey()) {
        throw new EntityStorageException('Criterion key cannot be changed.');
      }
      $wording_changed = $original->getText() !== $this->getText()
        || $original->getHelp() !== $this->getHelp();
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
   * @param array<int|string, \Drupal\lifebalance\Entity\Criterion> $entities
   *   Criteria that would be deleted.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    throw new EntityStorageException('Criteria cannot be deleted. Archive the criterion instead.');
  }

  /**
   * Rejects values that the catalog and the admin form must not store.
   */
  private function assertShape(): void {
    if (!preg_match(self::KEY_PATTERN, $this->getCatalogKey())) {
      throw new EntityStorageException('Criterion key must be 1–64 characters: lowercase letters, digits, dot, underscore, or hyphen.');
    }
    if (!preg_match(self::SECTOR_PATTERN, $this->getSectorId())) {
      throw new EntityStorageException('Criterion sector must be a machine name of at most 32 characters.');
    }
    if (!isset(self::tierLabels()[$this->getTier()])) {
      throw new EntityStorageException('Criterion tier must be free or premium.');
    }
    if (!isset(self::statusLabels()[$this->getStatus()])) {
      throw new EntityStorageException('Criterion status must be draft, published, or archived.');
    }
    if (!isset(self::originLabels()[$this->getOrigin()])) {
      throw new EntityStorageException('Criterion origin is not allowed.');
    }
    $text = $this->getText();
    if ($text === '' || mb_strlen($text) > 255) {
      throw new EntityStorageException('Criterion text must be 1 to 255 characters.');
    }
    $weight = $this->get('weight')->value;
    if ($weight === NULL || !is_numeric($weight)) {
      throw new EntityStorageException('Criterion weight is required.');
    }
  }

  /**
   * Field value as text. NULL and non-scalars become an empty string.
   */
  private function plain(string $field): string {
    $value = $this->get($field)->value;
    return is_scalar($value) ? (string) $value : '';
  }

}
