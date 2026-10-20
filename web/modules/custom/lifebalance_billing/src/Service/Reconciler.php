<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_billing\Repository\OrderRepository;

/**
 * Asks the provider about pending orders whose webhook never arrived.
 *
 * Five minutes must pass, and orders older than seven days are left alone.
 * Cron calls run().
 */
final class Reconciler {

  private const MIN_AGE = 300;

  private const MAX_AGE = 7 * 86400;

  public function __construct(
    private readonly OrderRepository $orders,
    private readonly PaymentGatewayInterface $gateway,
    private readonly PaymentProcessor $processor,
    private readonly Clock $clock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * How many pending orders were checked.
   */
  public function run(): int {
    $now = $this->clock->now();
    $checked = 0;
    foreach ($this->orders->loadPendingBetween($now - self::MAX_AGE, $now - self::MIN_AGE) as $order) {
      try {
        $event = $this->gateway->getStatus($order->getInvoiceId());
        if (!$event instanceof ProviderEvent) {
          continue;
        }
        $this->processor->apply($event, 'status_check');
        $checked++;
      }
      catch (\Throwable) {
        $this->loggerFactory->get('lifebalance_billing')->error('Billing reconciliation failed for order @id.', [
          '@id' => $order->id(),
        ]);
      }
    }
    return $checked;
  }

}
