<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Database\Connection;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Stage 4 totals. Only payment orders are read, never grants or scores.
 *
 * Revenue is the sum of orders that are still paid. A full refund or a
 * chargeback leaves that status, so it drops out. A manual grant is not an
 * order and cannot appear here.
 */
final class BillingStats {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Revenue, payments per plan, and how many accounts have a paid order.
   *
   * @return array{
   *   revenue_minor: int,
   *   plans: list<array{id: string, label: string, count: int}>,
   *   paid_people: int,
   *   accounts: int,
   *   checkout_people: int,
   *   bought_people: int,
   *   renewed_people: int
   *   }
   *   Counts and one sum. Plan labels are the snapshot stored on the order.
   */
  public function summary(int $since): array {
    return [
      'revenue_minor' => $this->revenue($since),
      'plans' => $this->plans($since),
      'paid_people' => $this->paidPeople(),
      'accounts' => $this->accounts(),
      'checkout_people' => $this->checkoutPeople($since),
      'bought_people' => $this->boughtPeople($since),
      'renewed_people' => $this->renewedPeople($since),
    ];
  }

  /**
   * Sum of orders that are still paid, in minor units.
   */
  private function revenue(int $since): int {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->condition('o.status', PaymentOrder::STATUS_PAID);
    if ($since > 0) {
      $query->condition('o.paid_at', $since, '>=');
    }
    $query->addExpression('SUM(o.amount_minor)', 'revenue');
    $value = $query->execute()?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Paid orders in the period, grouped by the stored plan.
   *
   * @return list<array{id: string, label: string, count: int}>
   *   One row per plan id.
   */
  private function plans(int $since): array {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->fields('o', ['plan_id', 'plan_label']);
    $query->condition('o.status', PaymentOrder::STATUS_PAID);
    if ($since > 0) {
      $query->condition('o.paid_at', $since, '>=');
    }
    $query->addExpression('COUNT(o.id)', 'paid_count');
    $query->groupBy('o.plan_id');
    $query->groupBy('o.plan_label');
    $query->orderBy('plan_label');
    $statement = $query->execute();
    if ($statement === NULL) {
      return [];
    }
    $rows = [];
    foreach ($statement as $record) {
      $id = $record->plan_id ?? '';
      $label = $record->plan_label ?? '';
      $count = $record->paid_count ?? 0;
      if (!is_string($id) || !is_string($label) || !is_numeric($count)) {
        continue;
      }
      $rows[] = [
        'id' => $id,
        'label' => $label,
        'count' => (int) $count,
      ];
    }
    return $rows;
  }

  /**
   * Distinct accounts that currently have a paid order.
   */
  private function paidPeople(): int {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->condition('o.status', PaymentOrder::STATUS_PAID);
    $query->condition('o.uid', 0, '>');
    $query->addExpression('COUNT(DISTINCT o.uid)', 'people');
    $value = $query->execute()?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Accounts that opened checkout. Every order status counts.
   */
  private function checkoutPeople(int $since): int {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->condition('o.uid', 0, '>');
    if ($since > 0) {
      $query->condition('o.created', $since, '>=');
    }
    $query->addExpression('COUNT(DISTINCT o.uid)', 'people');
    return $this->integer($query->execute()?->fetchField());
  }

  /**
   * Accounts with a paid order in the period.
   */
  private function boughtPeople(int $since): int {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->condition('o.status', PaymentOrder::STATUS_PAID);
    $query->condition('o.uid', 0, '>');
    if ($since > 0) {
      $query->condition('o.paid_at', $since, '>=');
    }
    $query->addExpression('COUNT(DISTINCT o.uid)', 'people');
    return $this->integer($query->execute()?->fetchField());
  }

  /**
   * Accounts with at least two paid orders. The later payment sets the period.
   */
  private function renewedPeople(int $since): int {
    $query = $this->database->select('lifebalance_payment_order', 'o');
    $query->addField('o', 'uid');
    $query->condition('o.status', PaymentOrder::STATUS_PAID);
    $query->condition('o.uid', 0, '>');
    $query->groupBy('o.uid');
    $query->having('COUNT(o.id) >= :needed', [':needed' => 2]);
    if ($since > 0) {
      $query->having('MAX(o.paid_at) >= :since', [':since' => $since]);
    }
    return $this->integer($query->countQuery()->execute()?->fetchField());
  }

  /**
   * A numeric SQL result, or zero.
   */
  private function integer(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Registered accounts. This is the denominator of the conversion.
   */
  private function accounts(): int {
    $query = $this->database->select('users_field_data', 'u');
    $query->condition('u.default_langcode', 1);
    $query->condition('u.uid', 0, '>');
    $value = $query->countQuery()->execute()?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

}
