<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * Where the buyer pays, and the id we store on the order.
 */
final class InvoiceResult {

  public function __construct(
    public readonly string $invoiceId,
    public readonly string $pageUrl,
  ) {}

}
