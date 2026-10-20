<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * One checklist step copied onto a goal.
 *
 * The text is a copy. Marking it done does not change a snapshot.
 */
#[FieldType(
  id: 'lifebalance_goal_step',
  label: new TranslatableMarkup('Крок цілі'),
  description: new TranslatableMarkup('Текст кроку, позначка виконання і час.'),
  no_ui: TRUE,
)]
final class GoalStepItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['text'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Текст'))
      ->setRequired(TRUE);
    $properties['done'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Виконано'))
      ->setRequired(TRUE);
    $properties['done_at'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Час виконання'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'text';
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
        'text' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'done' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
        ],
        'done_at' => [
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $text = $this->get('text')->getValue();
    return !is_string($text) || trim($text) === '';
  }

  /**
   * Step text.
   */
  public function getText(): string {
    $value = $this->get('text')->getValue();
    return is_string($value) ? $value : '';
  }

  /**
   * Whether the person marked this step done.
   */
  public function isDone(): bool {
    $value = $this->get('done')->getValue();
    return is_numeric($value) && (int) $value === 1;
  }

  /**
   * When the step was marked done, or NULL while it is open.
   */
  public function getDoneAt(): ?int {
    $value = $this->get('done_at')->getValue();
    if (!is_numeric($value)) {
      return NULL;
    }
    $time = (int) $value;
    return $time > 0 ? $time : NULL;
  }

}
