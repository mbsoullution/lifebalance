<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Derived snapshot fields plus any reason the snapshot cannot be stored.
 */
final class SnapshotReview {

  /**
   * Derived fields for one snapshot.
   *
   * @param string $mode
   *   Assessment mode: none, free, or premium.
   * @param string|null $formulaVersion
   *   Formula version, or NULL when no sector has a guide.
   * @param array<string, \Drupal\lifebalance\Service\GuideResult> $guides
   *   Guide per active sector ID.
   * @param list<string> $problems
   *   Problem codes from SnapshotProblem.
   */
  public function __construct(
    public readonly string $mode,
    public readonly ?string $formulaVersion,
    public readonly array $guides,
    public readonly array $problems,
  ) {}

}
