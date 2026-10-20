<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * A provider notice after the gateway has mapped the status.
 */
final class ProviderEvent {

  public function __construct(
    public readonly string $invoiceId,
    public readonly string $status,
    public readonly string $providerStatus,
    public readonly int $amountMinor,
    public readonly string $currency,
    public readonly string $reference,
    public readonly int $modifiedAt,
    public readonly string $payloadHash,
  ) {}

}
