<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * The price a checkout screen may show before a slot is reserved.
 */
final class PromoQuote {

  public function __construct(
    public readonly int $amountMinor,
    public readonly bool $confirmList,
    public readonly string $seats,
  ) {}

}
