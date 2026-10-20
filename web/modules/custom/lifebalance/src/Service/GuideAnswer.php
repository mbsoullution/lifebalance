<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * One answer passed into the guide formula.
 *
 * The calculator does not load criteria. The caller already knows the tier
 * and whether the statement belongs in the formula.
 */
final class GuideAnswer {

  public function __construct(
    public readonly string $tier,
    public readonly string $answer,
    public readonly bool $inFormula,
  ) {}

}
