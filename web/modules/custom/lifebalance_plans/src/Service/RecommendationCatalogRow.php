<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * One validated row from the recommendation catalog.
 */
final class RecommendationCatalogRow {

  /**
   * Constructs a catalog row.
   *
   * @param string $key
   *   Stable catalog key.
   * @param string $sector
   *   Sector ID.
   * @param list<string> $triggerCriteria
   *   Criterion keys in this sector.
   * @param list<string> $triggerAnswers
   *   Answer values, no and/or partial.
   * @param string $title
   *   Short title.
   * @param string $body
   *   Recommendation text.
   * @param list<string> $steps
   *   Checklist steps.
   * @param string $type
   *   Recommendation type.
   * @param string $effort
   *   Effort value.
   * @param string $safety
   *   Safety value.
   * @param string $tier
   *   Tier value.
   * @param int $weight
   *   Sort weight.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $sector,
    public readonly array $triggerCriteria,
    public readonly array $triggerAnswers,
    public readonly string $title,
    public readonly string $body,
    public readonly array $steps,
    public readonly string $type,
    public readonly string $effort,
    public readonly string $safety,
    public readonly string $tier,
    public readonly int $weight,
  ) {}

}
