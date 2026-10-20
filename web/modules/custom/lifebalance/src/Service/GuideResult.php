<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Guide for one sector.
 *
 * The guide is the displayed 0–10 score times 10, or NULL when the substantive
 * answers do not reach the minimum. answered counts yes, partial, and no.
 */
final class GuideResult {

  public function __construct(
    public readonly ?int $guide,
    public readonly int $answered,
  ) {}

}
