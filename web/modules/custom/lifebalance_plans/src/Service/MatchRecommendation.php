<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * A catalog row reduced to the fields the matcher reads.
 */
final class MatchRecommendation {

  /**
   * Constructs a catalog row for the matcher.
   *
   * @param string $key
   *   Stable catalog key.
   * @param string $sectorId
   *   Sector ID.
   * @param list<string> $triggerCriteria
   *   Criterion keys.
   * @param list<string> $triggerAnswers
   *   Answer values that count.
   * @param string $title
   *   Short title.
   * @param string $body
   *   Recommendation text.
   * @param list<string> $steps
   *   Checklist steps.
   * @param string $safety
   *   Safety value.
   * @param string $tier
   *   Tier value.
   * @param string $status
   *   Editorial status.
   * @param int $weight
   *   Sort weight.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $sectorId,
    public readonly array $triggerCriteria,
    public readonly array $triggerAnswers,
    public readonly string $title,
    public readonly string $body,
    public readonly array $steps,
    public readonly string $safety,
    public readonly string $tier,
    public readonly string $status,
    public readonly int $weight,
  ) {}

}
