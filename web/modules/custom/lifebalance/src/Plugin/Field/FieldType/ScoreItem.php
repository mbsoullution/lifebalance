<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * One sector score inside a snapshot.
 *
 * Value is the person's own score, 0 through 10. The guide is the server-side
 * hint times 10, or NULL. Answered is how many substantive answers fed it.
 */
#[FieldType(
  id: 'lifebalance_score',
  label: new TranslatableMarkup('Оцінка сектора'),
  description: new TranslatableMarkup('Остаточна оцінка сектора, орієнтир і кількість змістовних відповідей.'),
  no_ui: TRUE,
  constraints: [
    'ComplexData' => [
      'properties' => [
        'value' => [
          'Range' => [
            'min' => 0,
            'max' => 10,
            'notInRangeMessage' => 'Оцінка має бути від 0 до 10.',
          ],
        ],
        'guide' => [
          'Range' => [
            'min' => 0,
            'max' => 100,
            'notInRangeMessage' => 'Орієнтир має бути від 0 до 100.',
          ],
        ],
      ],
    ],
  ],
)]
final class ScoreItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['sector_id'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Сектор'))
      ->setRequired(TRUE);
    $properties['value'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Оцінка'))
      ->setRequired(TRUE);
    $properties['guide'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Орієнтир'));
    $properties['answered'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Змістовні відповіді'))
      ->setRequired(TRUE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Field schema.
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'sector_id' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'value' => [
          'type' => 'int',
          'size' => 'tiny',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'guide' => [
          'type' => 'int',
          'size' => 'small',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
        'answered' => [
          'type' => 'int',
          'size' => 'tiny',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $sector_id = $this->get('sector_id')->getValue();
    return $sector_id === NULL || $sector_id === '';
  }

  /**
   * Sector machine ID.
   */
  public function getSectorId(): string {
    $value = $this->get('sector_id')->getValue();
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Person's score, 0 through 10.
   */
  public function getScore(): int {
    $value = $this->get('value')->getValue();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Stored guide (displayed score times 10), or NULL when there is none.
   */
  public function getGuide(): ?int {
    $value = $this->get('guide')->getValue();
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Substantive answers that were eligible for the formula.
   */
  public function getAnswered(): int {
    $value = $this->get('answered')->getValue();
    return is_numeric($value) ? (int) $value : 0;
  }

}
