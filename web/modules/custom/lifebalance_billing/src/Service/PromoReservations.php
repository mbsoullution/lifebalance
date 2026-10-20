<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Reserves one launch99 slot. A missing slot never changes the price silently.
 *
 * The promo row is locked with SELECT … FOR UPDATE. paid_count grows in the
 * same transaction that grants access.
 */
final class PromoReservations {

  public const ID = 'launch99';

  public const PRICE_MINOR = 9900;

  /**
   * Promo invoice lifetime in seconds.
   */
  public const VALIDITY = 1800;

  /**
   * Extra seconds a reservation outlives its invoice.
   */
  public const HOLD = 300;

  /**
   * Catalog invoice lifetime in seconds.
   */
  public const LIST_VALIDITY = 86400;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Clock $clock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Price and seat band for a screen. This call does not reserve a slot.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person looking at the price.
   * @param \Drupal\lifebalance_billing\Entity\BillingPlan $plan
   *   The selected plan.
   */
  public function quote(AccountInterface $account, BillingPlan $plan): PromoQuote {
    $list = $plan->getPriceMinor();
    $row = $this->promoRow(FALSE);
    $now = $this->clock->now();
    if ($row === NULL || !$this->open($row, $now) || (string) $plan->id() !== 'premium_30') {
      return new PromoQuote($list, FALSE, '');
    }
    $use = $this->useRow((int) $account->id(), FALSE);
    if ($this->holdsSlot($use, $now) || ($this->eligible($account, $use) && $this->available($row, $now) > 0)) {
      return new PromoQuote($this->price($row), FALSE, $this->band($this->available($row, $now)));
    }
    if ($this->eligible($account, $use)) {
      return new PromoQuote($list, TRUE, 'none');
    }
    return new PromoQuote($list, FALSE, $this->band($this->available($row, $now)));
  }

  /**
   * Seat band for the public premium page, or an empty string when off.
   */
  public function publicSeats(): string {
    $row = $this->promoRow(FALSE);
    if ($row === NULL || !$this->open($row, $this->clock->now())) {
      return '';
    }
    return $this->band($this->available($row, $this->clock->now()));
  }

  /**
   * Locks the promo and chooses the amount. Throws when 149 needs a confirm.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person checking out.
   * @param \Drupal\lifebalance_billing\Entity\BillingPlan $plan
   *   The selected plan.
   * @param bool $acceptList
   *   TRUE when the person confirmed the catalog price.
   */
  public function begin(AccountInterface $account, BillingPlan $plan, bool $acceptList): PromoDecision {
    $list = $plan->getPriceMinor();
    $catalog = new PromoDecision($list, '', self::LIST_VALIDITY, NULL, FALSE);
    $row = $this->promoRow(TRUE);
    $now = $this->clock->now();
    $uid = (int) $account->id();
    $use = $uid > 0 ? $this->useRow($uid, TRUE) : NULL;
    if ($use !== NULL && $this->holdsSlot($use, $now)) {
      $order_id = is_numeric($use['order_id'] ?? NULL) ? (int) $use['order_id'] : 0;
      return new PromoDecision(
        $this->price($row ?? []),
        self::ID,
        self::VALIDITY,
        $this->uuidOf($order_id),
        FALSE,
      );
    }
    if ($acceptList || $row === NULL || !$this->open($row, $now) || (string) $plan->id() !== 'premium_30' || !$this->eligible($account, $use)) {
      return $catalog;
    }
    if ($this->available($row, $now) < 1) {
      throw new PromoListConfirmException('The promo has no free slot.');
    }
    return new PromoDecision($this->price($row), self::ID, self::VALIDITY, NULL, $use !== NULL);
  }

  /**
   * Stores the reservation for an order created inside the same transaction.
   *
   * @param int $uid
   *   The buyer.
   * @param int $orderId
   *   The new order id.
   * @param bool $replace
   *   TRUE when this person already has a released row.
   */
  public function attach(int $uid, int $orderId, bool $replace): void {
    $until = $this->clock->now() + self::VALIDITY + self::HOLD;
    if ($replace) {
      $this->database->update('lifebalance_billing_promo_use')
        ->fields([
          'order_id' => $orderId,
          'state' => 'reserved',
          'reserved_until' => $until,
        ])
        ->condition('promo_id', self::ID)
        ->condition('uid', $uid)
        ->execute();
      return;
    }
    $this->database->insert('lifebalance_billing_promo_use')
      ->fields([
        'promo_id' => self::ID,
        'uid' => $uid,
        'order_id' => $orderId,
        'state' => 'reserved',
        'reserved_until' => $until,
      ])
      ->execute();
  }

  /**
   * Marks the slot paid and increments the counter.
   *
   * @param \Drupal\lifebalance_billing\Entity\PaymentOrder $order
   *   The order that was just paid.
   */
  public function markPaid(PaymentOrder $order): void {
    if ($order->getPromoId() !== self::ID) {
      return;
    }
    $id = (int) $order->id();
    $query = $this->database->select('lifebalance_billing_promo_use', 'u')
      ->fields('u')
      ->condition('order_id', $id);
    $query->forUpdate();
    $row = $query->execute()?->fetchAssoc();
    if (is_array($row)) {
      if (($row['state'] ?? '') === 'paid') {
        return;
      }
      $until = is_numeric($row['reserved_until'] ?? NULL) ? (int) $row['reserved_until'] : 0;
      $this->database->update('lifebalance_billing_promo_use')
        ->fields(['state' => 'paid'])
        ->condition('order_id', $id)
        ->execute();
      $this->increment();
      if ($until < $this->clock->now()) {
        $this->alert($id);
      }
      return;
    }
    $this->increment();
    $this->alert($id);
  }

  /**
   * Frees a slot after a failed, expired, or cancelled invoice.
   *
   * @param \Drupal\lifebalance_billing\Entity\PaymentOrder $order
   *   The order that did not get paid.
   */
  public function release(PaymentOrder $order): void {
    if ($order->getPromoId() !== self::ID) {
      return;
    }
    $this->database->update('lifebalance_billing_promo_use')
      ->fields(['state' => 'released'])
      ->condition('order_id', (int) $order->id())
      ->condition('state', 'reserved')
      ->execute();
  }

  /**
   * Current counters for the admin form.
   *
   * @return array{active: int, slot_limit: int, paid_count: int, reserved: int, starts: int, ends: int}
   *   Stored values. Missing row counts as off.
   */
  public function settings(): array {
    $row = $this->promoRow(FALSE);
    $now = $this->clock->now();
    return [
      'active' => $row === NULL ? 0 : $this->flag($row, 'active'),
      'slot_limit' => $row === NULL ? 100 : $this->flag($row, 'slot_limit'),
      'paid_count' => $row === NULL ? 0 : $this->flag($row, 'paid_count'),
      'reserved' => $row === NULL ? 0 : $this->reserved($now),
      'starts' => $row === NULL ? 0 : $this->flag($row, 'starts'),
      'ends' => $row === NULL ? 0 : $this->flag($row, 'ends'),
    ];
  }

  /**
   * Saves the switch, the limit, and the window. The price stays 99 грн.
   *
   * @param bool $active
   *   Whether new checkouts may take a slot.
   * @param int $limit
   *   Maximum paid slots.
   * @param int $starts
   *   Window start, or 0.
   * @param int $ends
   *   Window end, or 0.
   */
  public function saveSettings(bool $active, int $limit, int $starts, int $ends): void {
    $fields = [
      'active' => $active ? 1 : 0,
      'slot_limit' => $limit,
      'starts' => $starts,
      'ends' => $ends,
      'price_minor' => self::PRICE_MINOR,
    ];
    if ($this->promoRow(FALSE) === NULL) {
      $fields['id'] = self::ID;
      $fields['paid_count'] = 0;
      $this->database->insert('lifebalance_billing_promo')->fields($fields)->execute();
      return;
    }
    $this->database->update('lifebalance_billing_promo')
      ->fields($fields)
      ->condition('id', self::ID)
      ->execute();
  }

  /**
   * Whether this person may still take the introductory price.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer.
   * @param array<string, mixed>|null $use
   *   Their promo row, when one exists.
   */
  private function eligible(AccountInterface $account, ?array $use): bool {
    $uid = (int) $account->id();
    if ($uid < 1 || ($use !== NULL && ($use['state'] ?? '') === 'paid')) {
      return FALSE;
    }
    $ids = $this->entityTypeManager->getStorage('lifebalance_payment_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', [
        PaymentOrder::STATUS_PAID,
        PaymentOrder::STATUS_REFUNDED,
        PaymentOrder::STATUS_PARTIALLY_REFUNDED,
        PaymentOrder::STATUS_CHARGEBACK,
      ], 'IN')
      ->range(0, 1)
      ->execute();
    return $ids === [];
  }

  /**
   * Whether the row is on and the clock is inside its window.
   *
   * @param array<string, mixed> $row
   *   The promo row.
   * @param int $now
   *   Unix time.
   */
  private function open(array $row, int $now): bool {
    if ($this->flag($row, 'active') !== 1) {
      return FALSE;
    }
    $starts = $this->flag($row, 'starts');
    $ends = $this->flag($row, 'ends');
    if ($starts > 0 && $now < $starts) {
      return FALSE;
    }
    if ($ends > 0 && $now > $ends) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Slots left: limit minus paid minus reservations that have not expired.
   *
   * @param array<string, mixed> $row
   *   The locked or current promo row.
   * @param int $now
   *   Unix time.
   */
  private function available(array $row, int $now): int {
    return $this->flag($row, 'slot_limit') - $this->flag($row, 'paid_count') - $this->reserved($now);
  }

  /**
   * Reservations that still hold a slot.
   *
   * @param int $now
   *   Unix time.
   */
  private function reserved(int $now): int {
    $count = $this->database->select('lifebalance_billing_promo_use', 'u')
      ->condition('promo_id', self::ID)
      ->condition('state', 'reserved')
      ->condition('reserved_until', $now, '>')
      ->countQuery()
      ->execute()
      ?->fetchField();
    return is_numeric($count) ? (int) $count : 0;
  }

  /**
   * Seat band without the exact number.
   *
   * @param int $available
   *   Slots left.
   */
  private function band(int $available): string {
    if ($available > 20) {
      return 'many';
    }
    if ($available > 0) {
      return 'few';
    }
    return 'none';
  }

  /**
   * TRUE when this person already holds a live reservation.
   *
   * @param array<string, mixed>|null $use
   *   Their promo row.
   * @param int $now
   *   Unix time.
   */
  private function holdsSlot(?array $use, int $now): bool {
    if ($use === NULL || ($use['state'] ?? '') !== 'reserved') {
      return FALSE;
    }
    $until = is_numeric($use['reserved_until'] ?? NULL) ? (int) $use['reserved_until'] : 0;
    return $until > $now;
  }

  /**
   * The promo price stored on the row, or the fixed 99 грн.
   *
   * @param array<string, mixed> $row
   *   The promo row.
   */
  private function price(array $row): int {
    $price = $this->flag($row, 'price_minor');
    return $price > 0 ? $price : self::PRICE_MINOR;
  }

  /**
   * One integer column.
   *
   * @param array<string, mixed> $row
   *   A database row.
   * @param string $key
   *   Column name.
   */
  private function flag(array $row, string $key): int {
    $value = $row[$key] ?? 0;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * The promo row. The checkout lock uses FOR UPDATE.
   *
   * @param bool $lock
   *   TRUE to lock the row.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL when the table has no promo.
   */
  private function promoRow(bool $lock): ?array {
    $query = $this->database->select('lifebalance_billing_promo', 'p')
      ->fields('p')
      ->condition('id', self::ID);
    if ($lock) {
      $query->forUpdate();
    }
    $row = $query->execute()?->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * This person's promo row.
   *
   * @param int $uid
   *   The buyer.
   * @param bool $lock
   *   TRUE to lock the row.
   *
   * @return array<string, mixed>|null
   *   The row, or NULL.
   */
  private function useRow(int $uid, bool $lock): ?array {
    if ($uid < 1) {
      return NULL;
    }
    $query = $this->database->select('lifebalance_billing_promo_use', 'u')
      ->fields('u')
      ->condition('promo_id', self::ID)
      ->condition('uid', $uid);
    if ($lock) {
      $query->forUpdate();
    }
    $row = $query->execute()?->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Order uuid for an existing reservation.
   *
   * @param int $orderId
   *   The reserved order.
   */
  private function uuidOf(int $orderId): ?string {
    $order = $this->entityTypeManager->getStorage('lifebalance_payment_order')->load($orderId);
    if (!$order instanceof PaymentOrder) {
      return NULL;
    }
    $uuid = $order->uuid();
    return $uuid === '' ? NULL : $uuid;
  }

  /**
   * Adds one paid slot.
   */
  private function increment(): void {
    $this->database->update('lifebalance_billing_promo')
      ->expression('paid_count', 'paid_count + 1')
      ->condition('id', self::ID)
      ->execute();
  }

  /**
   * Tells the admin that a payment arrived after the reservation.
   *
   * @param int $orderId
   *   The order id. The amount is not logged.
   */
  private function alert(int $orderId): void {
    $this->loggerFactory->get('lifebalance_billing')->error('Late promo payment for order @id.', [
      '@id' => $orderId,
    ]);
  }

}
