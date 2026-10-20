<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Loads orders for checkout, webhooks, and reconciliation.
 *
 * These lookups are by uuid or invoice id. The return page checks the owner
 * itself. There is no listing of every order.
 */
final class OrderRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * One order by uuid.
   */
  public function loadByUuid(string $uuid): ?PaymentOrder {
    if ($uuid === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $order = $storage->load($id);
    return $order instanceof PaymentOrder ? $order : NULL;
  }

  /**
   * One order by the provider invoice id.
   */
  public function loadByInvoiceId(string $invoiceId): ?PaymentOrder {
    if ($invoiceId === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_invoice_id', $invoiceId)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $order = $storage->load($id);
    return $order instanceof PaymentOrder ? $order : NULL;
  }

  /**
   * Pending orders created inside the reconciliation window.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\PaymentOrder>
   *   Orders older than five minutes and younger than seven days.
   */
  public function loadPendingBetween(int $oldest, int $newest): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', PaymentOrder::STATUS_PENDING)
      ->condition('created', $oldest, '>=')
      ->condition('created', $newest, '<=')
      ->sort('created', 'ASC')
      ->execute();
    $orders = [];
    foreach ($storage->loadMultiple($ids) as $order) {
      if ($order instanceof PaymentOrder) {
        $orders[] = $order;
      }
    }
    return $orders;
  }

}
