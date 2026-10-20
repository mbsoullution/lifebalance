<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * A locked checkout decision. The invoice uses this amount.
 */
final class PromoDecision {

  public function __construct(
    public readonly int $amountMinor,
    public readonly string $promoId,
    public readonly int $validity,
    public readonly ?string $existingUuid,
    public readonly bool $replaceUse,
  ) {}

}
