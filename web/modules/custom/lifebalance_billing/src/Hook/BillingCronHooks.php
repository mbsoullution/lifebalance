<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance_billing\Service\Reconciler;

/**
 * Reconciliation runs with cron. Pending orders outside the window are left.
 */
final class BillingCronHooks {

  public function __construct(
    private readonly Reconciler $reconciler,
  ) {}

  /**
   * Asks the provider about pending orders whose webhook did not arrive.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->reconciler->run();
  }

}
