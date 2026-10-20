<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * The single free preview, or none when nothing matches.
 */
final class PreviewResult {

  public function __construct(
    public readonly ?Suggestion $suggestion,
    public readonly bool $support,
    public readonly string $supportText,
  ) {}

}
