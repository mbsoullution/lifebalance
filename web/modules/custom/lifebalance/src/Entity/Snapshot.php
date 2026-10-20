<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Access\SnapshotAccessControlHandler;
use Drupal\lifebalance\Plugin\Field\FieldType\AnswerItem;
use Drupal\lifebalance\Plugin\Field\FieldType\ScoreItem;
use Drupal\lifebalance\SnapshotStorageSchema;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A dated self-assessment. Scores and answers stay as they were saved.
 *
 * The guide, answered count, assessment mode, formula version, and catalog
 * version are derived on the server. The person's own score is never
 * replaced by the guide.
 */
#[ContentEntityType(
  id: 'lifebalance_snapshot',
  label: new TranslatableMarkup('Зріз'),
  label_collection: new TranslatableMarkup('Зрізи'),
  label_singular: new TranslatableMarkup('зріз'),
  label_plural: new TranslatableMarkup('зрізи'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  handlers: [
    'storage_schema' => SnapshotStorageSchema::class,
    'access' => SnapshotAccessControlHandler::class,
  ],
  admin_permission: 'administer lifebalance snapshots',
  base_table: 'lifebalance_snapshot',
  translatable: FALSE,
  constraints: [
    'Snapshot' => [],
  ],
)]
class Snapshot extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const MODE_NONE = 'none';

  public const MODE_FREE = 'free';

  public const MODE_PREMIUM = 'premium';

  /**
   * Assessment mode labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by stored value.
   */
  public static function modeLabels(): array {
    return [
      self::MODE_NONE => new TranslatableMarkup('Без опитувальника'),
      self::MODE_FREE => new TranslatableMarkup('Базовий'),
      self::MODE_PREMIUM => new TranslatableMarkup('Розширений'),
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
        ->setDescription(new TranslatableMarkup('Людина, якій належить зріз.'))
        ->setRequired(TRUE);
    }

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Створено'))
      ->setDescription(new TranslatableMarkup('Час збереження зрізу в UTC.'));

    $fields['note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Нотатка'));

    $fields['assessment_mode'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Режим опитування'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::MODE_NONE)
      ->setSetting('allowed_values', self::modeLabels())
      ->setSetting('max_length', 32);

    $fields['formula_version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Версія формули'))
      ->setSetting('max_length', 16);

    $fields['catalog_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Версія каталогу'))
      ->setDescription(new TranslatableMarkup(
        'Версія каталогу критеріїв на момент збереження зрізу.',
      ))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('size', 'small');

    $fields['scores'] = BaseFieldDefinition::create('lifebalance_score')
      ->setLabel(new TranslatableMarkup('Оцінки'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $fields['answers'] = BaseFieldDefinition::create('lifebalance_answer')
      ->setLabel(new TranslatableMarkup('Відповіді'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    return $fields;
  }

  /**
   * Creation time as a UTC timestamp.
   */
  public function getCreatedTime(): int {
    $value = $this->get('created')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Private note. Empty when the person left it blank.
   */
  public function getNote(): string {
    $value = $this->get('note')->value;
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Highest mode present in the answers: none, free, or premium.
   */
  public function getAssessmentMode(): string {
    $value = $this->get('assessment_mode')->value;
    return is_string($value) && $value !== '' ? $value : self::MODE_NONE;
  }

  /**
   * Catalog version stored with this snapshot.
   */
  public function getCatalogVersion(): int {
    $value = $this->get('catalog_version')->value;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Formula version, or NULL when no sector has a guide.
   */
  public function getFormulaVersion(): ?string {
    $value = $this->get('formula_version')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Sector scores, skipping empty items.
   *
   * @return list<\Drupal\lifebalance\Plugin\Field\FieldType\ScoreItem>
   *   Score items.
   */
  public function getScores(): array {
    $items = [];
    foreach ($this->get('scores') as $item) {
      if ($item instanceof ScoreItem && !$item->isEmpty()) {
        $items[] = $item;
      }
    }
    return $items;
  }

  /**
   * Answers, skipping empty items.
   *
   * @return list<\Drupal\lifebalance\Plugin\Field\FieldType\AnswerItem>
   *   Answer items.
   */
  public function getAnswers(): array {
    $items = [];
    foreach ($this->get('answers') as $item) {
      if ($item instanceof AnswerItem && !$item->isEmpty()) {
        $items[] = $item;
      }
    }
    return $items;
  }

}
