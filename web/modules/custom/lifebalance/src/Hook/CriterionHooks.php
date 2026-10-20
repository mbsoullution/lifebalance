<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Service\Clock;

/**
 * Stamps criterion revisions with the module clock.
 */
final class CriterionHooks {

  public function __construct(
    private readonly Clock $clock,
  ) {}

  /**
   * Sets the revision time when a new revision is about to be stored.
   */
  #[Hook('lifebalance_criterion_presave')]
  public function presave(Criterion $criterion): void {
    if ($criterion->isNewRevision()) {
      $criterion->setRevisionCreationTime($this->clock->now());
    }
  }

}
