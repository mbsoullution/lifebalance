<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Starts a checkout. The return URL does not grant access.
 */
final class OrderManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PaymentGatewayInterface $gateway,
    private readonly EntitlementManager $entitlements,
    private readonly PromoReservations $promos,
    private readonly Connection $database,
  ) {}

  /**
   * Active plan, or NULL.
   */
  public function loadPlan(string $id): ?BillingPlan {
    if ($id === '') {
      return NULL;
    }
    $plan = $this->entityTypeManager->getStorage('lifebalance_billing_plan')->load($id);
    if (!$plan instanceof BillingPlan || !$plan->status()) {
      return NULL;
    }
    return $plan;
  }

  /**
   * Whether an open-ended grant already blocks a purchase.
   */
  public function blockedByOpenEnded(AccountInterface $account): bool {
    return $this->entitlements->nextPaidStart($account) === NULL;
  }

  /**
   * When paid access would start and end.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person checking out.
   * @param \Drupal\lifebalance_billing\Entity\BillingPlan $plan
   *   The selected plan.
   *
   * @return array{starts: int, ends: int}|null
   *   Instants, or NULL for an open-ended grant.
   */
  public function accessWindow(AccountInterface $account, BillingPlan $plan): ?array {
    $starts = $this->entitlements->nextPaidStart($account);
    if ($starts === NULL) {
      return NULL;
    }
    return [
      'starts' => $starts,
      'ends' => $this->entitlements->endOfDays($starts, $plan->getDurationDays()),
    ];
  }

  /**
   * The price the checkout screen shows. This does not reserve a slot.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person checking out.
   * @param \Drupal\lifebalance_billing\Entity\BillingPlan $plan
   *   The selected plan.
   */
  public function quote(AccountInterface $account, BillingPlan $plan): PromoQuote {
    return $this->promos->quote($account, $plan);
  }

  /**
   * Stores a price snapshot and returns the hosted payment page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person checking out.
   * @param \Drupal\lifebalance_billing\Entity\BillingPlan $plan
   *   The selected plan.
   * @param bool $acceptList
   *   TRUE when the person confirmed the catalog price.
   */
  public function start(AccountInterface $account, BillingPlan $plan, bool $acceptList = FALSE): string {
    if ($this->blockedByOpenEnded($account)) {
      throw new \InvalidArgumentException('An open-ended entitlement blocks a new payment.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $decision = $this->promos->begin($account, $plan, $acceptList);
      if ($decision->existingUuid !== NULL) {
        return $this->returnUrl($decision->existingUuid);
      }
      $order = PaymentOrder::create([
        'uid' => $account->id(),
        'plan_id' => $plan->id(),
        'plan_label' => (string) $plan->label(),
        'duration_days' => $plan->getDurationDays(),
        'amount_minor' => $decision->amountMinor,
        'list_price_minor' => $plan->getPriceMinor(),
        'promo_id' => $decision->promoId,
        'currency' => $plan->getCurrency(),
        'status' => PaymentOrder::STATUS_DRAFT,
        'provider' => $this->gateway->id(),
      ]);
      $order->save();
      if ($decision->promoId !== '') {
        $this->promos->attach((int) $account->id(), (int) $order->id(), $decision->replaceUse);
      }
      $uuid = $order->uuid();
      $redirect = $this->returnUrl($uuid);
      $webhook = Url::fromRoute('lifebalance_billing.webhook', ['provider' => $this->gateway->id()], ['absolute' => TRUE])->toString();
      $invoice = $this->gateway->createInvoice(
        $order->getAmountMinor(),
        $order->getCurrency(),
        $uuid,
        $redirect,
        $webhook,
        $decision->validity,
      );
      $order->set('provider_invoice_id', $invoice->invoiceId);
      $order->set('status', PaymentOrder::STATUS_PENDING);
      $order->save();
      return $invoice->pageUrl;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Absolute return URL for one order uuid.
   *
   * @param string $uuid
   *   The order uuid.
   */
  private function returnUrl(string $uuid): string {
    return Url::fromRoute('lifebalance_billing.return', ['uuid' => $uuid], ['absolute' => TRUE])->toString();
  }

}
