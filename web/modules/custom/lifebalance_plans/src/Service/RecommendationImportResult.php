<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * Counts from one recommendation import.
 */
final class RecommendationImportResult {

  public function __construct(
    public readonly int $created,
    public readonly int $updated,
    public readonly int $unchanged,
    public readonly int $warned,
  ) {}

}
