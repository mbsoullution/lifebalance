<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * Suggestions for one sector the person chose.
 */
final class SectorSuggestions {

  /**
   * Constructs the suggestions for one sector.
   *
   * @param string $sectorId
   *   Sector ID.
   * @param list<\Drupal\lifebalance_plans\Service\Suggestion> $items
   *   Suggestions in display order.
   */
  public function __construct(
    public readonly string $sectorId,
    public readonly array $items,
  ) {}

}
