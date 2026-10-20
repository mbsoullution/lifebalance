<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Filtered order lookup for the finance list. Scores are not on this entity.
 */
final class OrderAdminList {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * One page of orders. The pager limit is 50.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\PaymentOrder>
   *   Newest first.
   */
  public function page(string $status, string $plan, int $from, int $to): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $this->narrow($query, $status, $plan, $from, $to);
    $query->sort('created', 'DESC');
    $query->pager(50);
    return $this->load($query->execute());
  }

  /**
   * Orders of one account, newest first, at most 50.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\PaymentOrder>
   *   That account's orders.
   */
  public function forOwner(int $uid): array {
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();
    return $this->load($ids);
  }

  /**
   * Plan options for the filter. The empty choice means every plan.
   *
   * @return array<string, string>
   *   Labels keyed by plan id.
   */
  public function planOptions(): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_billing_plan');
    $options = [];
    foreach ($storage->loadMultiple() as $plan) {
      if ($plan instanceof BillingPlan) {
        $options[$plan->id()] = (string) $plan->label();
      }
    }
    return $options;
  }

  /**
   * Unix time of a UTC calendar day, or 0 when the text is not a date.
   */
  public function dayStart(string $value): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return 0;
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    if (!$parsed instanceof \DateTimeImmutable) {
      return 0;
    }
    $errors = \DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
      return 0;
    }
    return $parsed->getTimestamp();
  }

  /**
   * Applies the allowlisted filters.
   */
  private function narrow(QueryInterface $query, string $status, string $plan, int $from, int $to): void {
    if ($status !== '' && array_key_exists($status, PaymentOrder::statusLabels())) {
      $query->condition('status', $status);
    }
    if ($plan !== '' && array_key_exists($plan, $this->planOptions())) {
      $query->condition('plan_id', $plan);
    }
    if ($from > 0) {
      $query->condition('created', $from, '>=');
    }
    if ($to > 0) {
      $query->condition('created', $to, '<=');
    }
  }

  /**
   * Loads the ids that came back from a query.
   *
   * @param array<int|string, int|string>|null $ids
   *   Entity ids.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\PaymentOrder>
   *   Orders that still exist.
   */
  private function load(?array $ids): array {
    if ($ids === NULL || $ids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $loaded = $storage->loadMultiple($ids);
    $orders = [];
    foreach ($ids as $id) {
      $order = $loaded[$id] ?? NULL;
      if ($order instanceof PaymentOrder) {
        $orders[] = $order;
      }
    }
    return $orders;
  }

}
