<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * Full-engine result for the sectors a person chose.
 */
final class FocusResult {

  /**
   * Constructs a focus result.
   *
   * @param list<\Drupal\lifebalance_plans\Service\SectorSuggestions> $sectors
   *   One entry per requested sector, in that order.
   * @param bool $support
   *   Whether the support block is shown.
   * @param string $supportText
   *   Configured support text.
   */
  public function __construct(
    public readonly array $sectors,
    public readonly bool $support,
    public readonly string $supportText,
  ) {}

}
