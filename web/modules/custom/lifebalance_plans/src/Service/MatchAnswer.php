<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * One "no" or "partial" answer the matcher can use.
 */
final class MatchAnswer {

  public function __construct(
    public readonly string $criterionKey,
    public readonly string $sectorId,
    public readonly string $answer,
    public readonly int $criterionWeight,
  ) {}

}
