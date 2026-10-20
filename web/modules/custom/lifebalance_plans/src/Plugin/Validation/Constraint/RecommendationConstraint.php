<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks triggers, sector, and who may publish a recommendation.
 */
#[Constraint(
  id: 'Recommendation',
  label: new TranslatableMarkup('Рекомендація', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class RecommendationConstraint extends SymfonyConstraint {}
