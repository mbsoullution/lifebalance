<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Finance actions. A full refund asks the provider. A partial one does not.
 *
 * Access changes only after a paid, refunded, or chargeback status is stored.
 */
final class OrderActions {

  public function __construct(
    private readonly PaymentGatewayInterface $gateway,
    private readonly PaymentProcessor $processor,
    private readonly EntitlementManager $entitlements,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventLog $events,
    private readonly Clock $clock,
  ) {}

  /**
   * Asks for a full refund, then applies the provider status when it is known.
   */
  public function refund(PaymentOrder $order): string {
    if ($order->getStatus() !== PaymentOrder::STATUS_PAID || $order->getInvoiceId() === '') {
      throw new \InvalidArgumentException('This order cannot be refunded.');
    }
    $this->gateway->refund($order->getInvoiceId());
    $event = $this->gateway->getStatus($order->getInvoiceId());
    if (!$event instanceof ProviderEvent) {
      return 'submitted';
    }
    return $this->processor->apply($event, 'status_check');
  }

  /**
   * Marks a dispute and revokes the access this payment created.
   */
  public function chargeback(PaymentOrder $order): string {
    if ($order->getStatus() !== PaymentOrder::STATUS_PAID || $order->getInvoiceId() === '') {
      throw new \InvalidArgumentException('This order cannot be marked.');
    }
    $modified = max($this->clock->now(), $order->getProviderModifiedAt() + 1);
    $body = 'chargeback:' . $order->uuid();
    $event = new ProviderEvent(
      $order->getInvoiceId(),
      PaymentOrder::STATUS_CHARGEBACK,
      PaymentOrder::STATUS_CHARGEBACK,
      $order->getAmountMinor(),
      $order->getCurrency(),
      $order->uuid(),
      $modified,
      hash('sha256', $body),
    );
    return $this->processor->apply($event, 'admin_action');
  }

  /**
   * Revokes access after a partial refund. The order status stays as it is.
   */
  public function revokePartial(PaymentOrder $order, string $reason): void {
    if ($order->getStatus() !== PaymentOrder::STATUS_PARTIALLY_REFUNDED) {
      throw new \InvalidArgumentException('This order has no partial refund.');
    }
    $entitlement = $this->entitlement($order);
    if (!$entitlement instanceof Entitlement) {
      throw new \InvalidArgumentException('This order has no access to revoke.');
    }
    $this->entitlements->revokeForRefund($entitlement, $reason);
    $this->events->append(
      (int) $order->id(),
      'admin_action',
      PaymentOrder::STATUS_PARTIALLY_REFUNDED,
      hash('sha256', 'partial:' . $order->uuid()),
      'revoked',
    );
  }

  /**
   * Whether a person still has to decide about access after a partial refund.
   */
  public function partialIsOpen(PaymentOrder $order): bool {
    if ($order->getStatus() !== PaymentOrder::STATUS_PARTIALLY_REFUNDED) {
      return FALSE;
    }
    $entitlement = $this->entitlement($order);
    return $entitlement instanceof Entitlement && $entitlement->getStatus() === Entitlement::STATUS_ACTIVE;
  }

  /**
   * Payment entitlement for this order, when it is still stored.
   */
  private function entitlement(PaymentOrder $order): ?Entitlement {
    $id = $order->getEntitlementId();
    if ($id < 1) {
      return NULL;
    }
    $loaded = $this->entityTypeManager->getStorage('lifebalance_entitlement')->load($id);
    return $loaded instanceof Entitlement ? $loaded : NULL;
  }

}
