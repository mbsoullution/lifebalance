<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * One questionnaire answer stored on the snapshot that received it.
 *
 * No row means the person left the statement unanswered.
 */
#[FieldType(
  id: 'lifebalance_answer',
  label: new TranslatableMarkup('Відповідь на критерій'),
  description: new TranslatableMarkup('Відповідь на конкретну ревізію критерію.'),
  no_ui: TRUE,
  constraints: [
    'ComplexData' => [
      'properties' => [
        'answer' => [
          'Choice' => [
            'choices' => ['yes', 'partial', 'no', 'na'],
            'message' => 'Відповідь має бути «так», «частково», «ні» або «не стосується».',
          ],
        ],
      ],
    ],
  ],
)]
final class AnswerItem extends FieldItemBase {

  public const YES = 'yes';

  public const PARTIAL = 'partial';

  public const NO = 'no';

  public const NA = 'na';

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['criterion_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Критерій'))
      ->setRequired(TRUE);
    $properties['criterion_revision_id'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Ревізія критерію'))
      ->setRequired(TRUE);
    $properties['answer'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Відповідь'))
      ->setRequired(TRUE);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'answer';
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
        'criterion_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'criterion_revision_id' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'answer' => [
          'type' => 'varchar',
          'length' => 16,
          'not null' => TRUE,
        ],
      ],
      'indexes' => [
        'criterion_id' => ['criterion_id'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $id = $this->get('criterion_id')->getValue();
    return $id === NULL || $id === '' || (int) $id === 0;
  }

  /**
   * Criterion entity ID.
   */
  public function getCriterionId(): int {
    $value = $this->get('criterion_id')->getValue();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Criterion revision the person answered.
   */
  public function getCriterionRevisionId(): int {
    $value = $this->get('criterion_revision_id')->getValue();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Stored answer: yes, partial, no, or na.
   */
  public function getAnswer(): string {
    $value = $this->get('answer')->getValue();
    return is_scalar($value) ? (string) $value : '';
  }

}
