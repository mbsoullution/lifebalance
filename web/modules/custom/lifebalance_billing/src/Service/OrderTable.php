<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Renders order rows. The cells are plan, amount, status, and date.
 */
final class OrderTable {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TranslationInterface $translation,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * A table of orders. Refund links need the refund permission.
   *
   * @param list<\Drupal\lifebalance_billing\Entity\PaymentOrder> $orders
   *   Orders to show.
   *
   * @return array<string, mixed>
   *   A table render array.
   */
  public function build(array $orders): array {
    $actions = $this->currentUser->hasPermission('refund lifebalance orders');
    $header = [
      (string) $this->translation->translate('Дата'),
      (string) $this->translation->translate('Обліковий запис'),
      (string) $this->translation->translate('План'),
      (string) $this->translation->translate('Сума'),
      (string) $this->translation->translate('Статус'),
    ];
    if ($actions) {
      $header[] = (string) $this->translation->translate('Дії');
    }
    $rows = [];
    foreach ($orders as $order) {
      $row = [
        $this->dateFormatter->format($order->getCreatedTime(), 'custom', 'd.m.Y H:i'),
        $this->owner($order),
        (string) $order->get('plan_label')->value,
        MinorMoney::format($order->getAmountMinor()) . ' грн',
        $this->status($order),
      ];
      if ($actions) {
        $row[] = ['data' => $this->actionCell($this->links($order))];
      }
      $rows[] = $row;
    }
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => (string) $this->translation->translate('Замовлень немає.'),
    ];
  }

  /**
   * Account id, without the mail address.
   */
  private function owner(PaymentOrder $order): string {
    $uid = $order->getOwnerId();
    if ($uid < 1) {
      return (string) $this->translation->translate('Видалений обліковий запис');
    }
    return (string) $uid;
  }

  /**
   * Ukrainian status label.
   */
  private function status(PaymentOrder $order): string {
    $labels = PaymentOrder::statusLabels();
    $label = $labels[$order->getStatus()] ?? $order->getStatus();
    return (string) $label;
  }

  /**
   * Action links that match the stored status.
   *
   * @return list<array<string, mixed>>
   *   Link render elements.
   */
  private function links(PaymentOrder $order): array {
    $items = [];
    $uuid = $order->uuid();
    if ($order->getStatus() === PaymentOrder::STATUS_PAID && $order->getInvoiceId() !== '') {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->translation->translate('Повернути'),
        '#url' => Url::fromRoute('lifebalance_billing.order_refund', ['uuid' => $uuid]),
      ];
      $items[] = [
        '#type' => 'link',
        '#title' => $this->translation->translate('Спір'),
        '#url' => Url::fromRoute('lifebalance_billing.order_chargeback', ['uuid' => $uuid]),
      ];
    }
    if ($order->getStatus() === PaymentOrder::STATUS_PARTIALLY_REFUNDED && $this->accessOpen($order)) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->translation->translate('Скасувати доступ'),
        '#url' => Url::fromRoute('lifebalance_billing.order_partial', ['uuid' => $uuid]),
      ];
    }
    return $items;
  }

  /**
   * Whether the payment access is still active.
   */
  private function accessOpen(PaymentOrder $order): bool {
    $id = $order->getEntitlementId();
    if ($id < 1) {
      return FALSE;
    }
    $entitlement = $this->entityTypeManager->getStorage('lifebalance_entitlement')->load($id);
    return $entitlement instanceof Entitlement && $entitlement->getStatus() === Entitlement::STATUS_ACTIVE;
  }

  /**
   * One cell of action links.
   *
   * @param list<array<string, mixed>> $links
   *   Link elements.
   *
   * @return array<string, mixed>
   *   A container, empty when there is nothing to do.
   */
  private function actionCell(array $links): array {
    $cell = ['#type' => 'container'];
    foreach ($links as $index => $link) {
      $cell['link_' . $index] = $link;
    }
    return $cell;
  }

}
