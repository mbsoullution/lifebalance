<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Drupal\user\UserInterface;

/**
 * Applies one provider notice. Access changes only inside the paid path.
 *
 * The order row is locked. A repeated or older notice does nothing.
 */
final class PaymentProcessor {

  public function __construct(
    private readonly OrderRepository $orders,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementManager $entitlements,
    private readonly EventLog $events,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly Clock $clock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly BillingNotifier $notifier,
    private readonly PromoReservations $promos,
  ) {}

  /**
   * Applies a mapped notice. Returns a short result code.
   */
  public function apply(ProviderEvent $event, string $type): string {
    $order = $this->orders->loadByInvoiceId($event->invoiceId);
    if (!$order instanceof PaymentOrder) {
      $this->loggerFactory->get('lifebalance_billing')->warning('Ignored a billing notice for an unknown invoice.');
      return 'unknown';
    }
    $name = 'lifebalance_billing_order:' . $order->id();
    if (!$this->lock->acquire($name, 30.0)) {
      $this->lock->wait($name, 5);
      if (!$this->lock->acquire($name, 30.0)) {
        return 'busy';
      }
    }
    try {
      $result = $this->applyLocked($order, $event, $type);
    }
    finally {
      $this->lock->release($name);
    }
    if ($result === 'applied') {
      $fresh = $this->orders->loadByInvoiceId($event->invoiceId);
      if ($fresh instanceof PaymentOrder) {
        try {
          $this->notifier->afterStatus($fresh);
        }
        catch (\Throwable) {
          $this->loggerFactory->get('lifebalance_billing')->error('Billing mail failed for order @id.', [
            '@id' => $fresh->id(),
          ]);
        }
      }
    }
    return $result;
  }

  /**
   * Reloads the order under a row lock and applies the notice once.
   */
  private function applyLocked(PaymentOrder $stale, ProviderEvent $event, string $type): string {
    $transaction = $this->database->startTransaction();
    try {
      $id = (int) $stale->id();
      $this->database->query('SELECT id FROM {lifebalance_payment_order} WHERE id = :id FOR UPDATE', [
        ':id' => $id,
      ]);
      $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
      $storage->resetCache([$id]);
      $order = $storage->load($id);
      if (!$order instanceof PaymentOrder) {
        return 'unknown';
      }
      if ($event->modifiedAt <= $order->getProviderModifiedAt()) {
        $this->events->append($id, $type, $event->providerStatus, $event->payloadHash, 'ignored');
        return 'ignored';
      }
      if (!$this->matchesOrder($order, $event)) {
        $this->markMismatch($order, $event, $type);
        return 'mismatch';
      }
      $next = $this->nextStatus($order->getStatus(), $event->status);
      if ($next === NULL) {
        $order->set('provider_modified_at', $event->modifiedAt);
        $order->save();
        $this->events->append($id, $type, $event->providerStatus, $event->payloadHash, 'ignored');
        return 'ignored';
      }
      $result = $this->move($order, $next, $event, $type);
      return $result;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Whether the notice still describes this order.
   */
  private function matchesOrder(PaymentOrder $order, ProviderEvent $event): bool {
    return $event->amountMinor === $order->getAmountMinor()
      && $event->currency === $order->getCurrency()
      && $event->reference === $order->uuid();
  }

  /**
   * Records a notice that does not match the stored price or reference.
   */
  private function markMismatch(PaymentOrder $order, ProviderEvent $event, string $type): void {
    if (in_array($order->getStatus(), [PaymentOrder::STATUS_DRAFT, PaymentOrder::STATUS_PENDING], TRUE)) {
      $order->set('status', PaymentOrder::STATUS_FAILED);
    }
    $order->set('provider_modified_at', $event->modifiedAt);
    $order->save();
    $this->events->append((int) $order->id(), $type, $event->providerStatus, $event->payloadHash, 'mismatch');
    $this->loggerFactory->get('lifebalance_billing')->error('Amount mismatch for order @id.', [
      '@id' => $order->id(),
    ]);
  }

  /**
   * Next status, or NULL when the notice must not change the order.
   */
  private function nextStatus(string $current, string $incoming): ?string {
    if ($incoming === $current) {
      return NULL;
    }
    $allowed = [
      PaymentOrder::STATUS_PENDING => [
        PaymentOrder::STATUS_PAID,
        PaymentOrder::STATUS_FAILED,
        PaymentOrder::STATUS_EXPIRED,
        PaymentOrder::STATUS_CANCELLED,
        PaymentOrder::STATUS_REFUNDED,
      ],
      PaymentOrder::STATUS_FAILED => [
        PaymentOrder::STATUS_PAID,
      ],
      PaymentOrder::STATUS_PAID => [
        PaymentOrder::STATUS_REFUNDED,
        PaymentOrder::STATUS_PARTIALLY_REFUNDED,
        PaymentOrder::STATUS_CHARGEBACK,
      ],
    ];
    $next = $allowed[$current] ?? [];
    return in_array($incoming, $next, TRUE) ? $incoming : NULL;
  }

  /**
   * Writes the new status and grants or revokes access when required.
   */
  private function move(PaymentOrder $order, string $next, ProviderEvent $event, string $type): string {
    $id = (int) $order->id();
    if ($next === PaymentOrder::STATUS_PAID) {
      $granted = $this->grant($order);
      if (!$granted) {
        $order->set('provider_modified_at', $event->modifiedAt);
        $order->save();
        $this->events->append($id, $type, $event->providerStatus, $event->payloadHash, 'blocked');
        $this->loggerFactory->get('lifebalance_billing')->error('Payment for order @id did not grant access.', [
          '@id' => $id,
        ]);
        return 'blocked';
      }
      $order->set('paid_at', $this->clock->now());
      $order->set('entitlement_id', $granted);
      $this->promos->markPaid($order);
    }
    if (in_array($next, [
      PaymentOrder::STATUS_FAILED,
      PaymentOrder::STATUS_EXPIRED,
      PaymentOrder::STATUS_CANCELLED,
    ], TRUE)) {
      $this->promos->release($order);
    }
    if (in_array($next, [
      PaymentOrder::STATUS_REFUNDED,
      PaymentOrder::STATUS_PARTIALLY_REFUNDED,
      PaymentOrder::STATUS_CHARGEBACK,
    ], TRUE)) {
      $order->set('refunded_at', $this->clock->now());
    }
    if (in_array($next, [PaymentOrder::STATUS_REFUNDED, PaymentOrder::STATUS_CHARGEBACK], TRUE)) {
      $this->revoke($order, $next);
    }
    $order->set('status', $next);
    $order->set('provider_modified_at', $event->modifiedAt);
    $order->save();
    $this->events->append($id, $type, $event->providerStatus, $event->payloadHash, 'applied');
    return 'applied';
  }

  /**
   * Creates the single entitlement for this order, or 0 when blocked.
   */
  private function grant(PaymentOrder $order): int {
    $account = $this->owner($order);
    if (!$account instanceof UserInterface) {
      return 0;
    }
    $starts = $this->entitlements->nextPaidStart($account);
    if ($starts === NULL) {
      return 0;
    }
    $ends = $this->entitlements->endOfDays($starts, $order->getDurationDays());
    try {
      $entitlement = $this->entitlements->activatePaid($account, (int) $order->id(), $ends);
    }
    catch (\InvalidArgumentException | EntityStorageException) {
      $entitlement = $this->existingGrant((int) $order->id());
      if (!$entitlement instanceof Entitlement) {
        return 0;
      }
    }
    return (int) $entitlement->id();
  }

  /**
   * Revokes the payment entitlement when the provider returns the money.
   */
  private function revoke(PaymentOrder $order, string $next): void {
    $entitlement_id = $order->getEntitlementId();
    if ($entitlement_id < 1) {
      return;
    }
    $entitlement = $this->entityTypeManager->getStorage('lifebalance_entitlement')->load($entitlement_id);
    if (!$entitlement instanceof Entitlement || $entitlement->getStatus() === Entitlement::STATUS_REVOKED) {
      return;
    }
    $reason = 'order:' . $order->id() . ' ' . $next;
    $this->entitlements->revokeForRefund($entitlement, $reason);
  }

  /**
   * The person who checked out, when the account still exists.
   */
  private function owner(PaymentOrder $order): ?UserInterface {
    $uid = $order->getOwnerId();
    if ($uid < 1) {
      return NULL;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Entitlement already stored for this order, if the unique key won a race.
   */
  private function existingGrant(int $orderId): ?Entitlement {
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $entitlement = $storage->load($id);
    return $entitlement instanceof Entitlement ? $entitlement : NULL;
  }

}
