<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * Formats a minor-unit amount as hryvnias.
 */
final class MinorMoney {

  /**
   * Formats 14900 as 149,00.
   */
  public static function format(int $minor): string {
    $sign = $minor < 0 ? '-' : '';
    $absolute = abs($minor);
    $cents = $absolute % 100;
    return $sign . intdiv($absolute, 100) . ',' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
  }

}
