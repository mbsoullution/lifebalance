<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that a snapshot's scores and answers can be stored.
 */
#[Constraint(
  id: 'Snapshot',
  label: new TranslatableMarkup('Зріз', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class SnapshotConstraint extends SymfonyConstraint {
}
