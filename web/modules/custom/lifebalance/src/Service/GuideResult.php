<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Guide for one sector.
 *
 * The guide is the rounded 0–10 score times 10, or NULL when the substantive
 * answers do not reach the minimum. precise is that same average in tenths
 * (85 means 8,5) and stays NULL together with the guide. answered counts yes,
 * partial, and no.
 */
final class GuideResult {

  public function __construct(
    public readonly ?int $guide,
    public readonly int $answered,
    public readonly ?int $precise,
  ) {}

  /**
   * One-decimal label, or a dash when the score is not available.
   */
  public static function formatTenths(?int $tenths): string {
    if ($tenths === NULL) {
      return '—';
    }
    return intdiv($tenths, 10) . ',' . ($tenths % 10);
  }

}
