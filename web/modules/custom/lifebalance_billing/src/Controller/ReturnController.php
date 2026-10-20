<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows the stored status. Opening this page never grants access.
 */
final class ReturnController extends ControllerBase {

  public function __construct(
    protected OrderRepository $orders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OrderRepository::class),
    );
  }

  /**
   * Waiting copy for the owner.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(string $uuid): array {
    $order = $this->owned($uuid);
    $url = Url::fromRoute('lifebalance_billing.return_status', ['uuid' => $uuid]);
    return [
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => ['lifebalance_billing/return'],
        'drupalSettings' => [
          'lifebalanceBilling' => [
            'url' => $url->toString(),
            'paid' => (string) $this->t('Оплату зараховано.'),
            'open' => (string) $this->t('Оплата обробляється, ми надішлемо лист.'),
            'stopped' => (string) $this->t('Оплату не завершено.'),
          ],
        ],
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->message($order),
        '#attributes' => [
          'data-lifebalance-billing-status' => $order->getStatus(),
        ],
      ],
    ];
  }

  /**
   * JSON status for the owner. It does not change the order.
   */
  public function status(string $uuid): JsonResponse {
    $order = $this->owned($uuid);
    return new JsonResponse(['status' => $order->getStatus()]);
  }

  /**
   * The order when the current user owns it.
   */
  private function owned(string $uuid): PaymentOrder {
    $order = $this->orders->loadByUuid($uuid);
    $uid = (int) $this->currentUser()->id();
    if (!$order instanceof PaymentOrder || $uid < 1 || $order->getOwnerId() !== $uid) {
      throw new NotFoundHttpException();
    }
    return $order;
  }

  /**
   * Sentence for the stored status.
   */
  private function message(PaymentOrder $order): string {
    return match ($order->getStatus()) {
      PaymentOrder::STATUS_PAID => (string) $this->t('Оплату зараховано.'),
      PaymentOrder::STATUS_FAILED, PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_CANCELLED => (string) $this->t('Оплату не завершено.'),
      PaymentOrder::STATUS_REFUNDED, PaymentOrder::STATUS_CHARGEBACK => (string) $this->t('Оплату повернено.'),
      PaymentOrder::STATUS_PARTIALLY_REFUNDED => (string) $this->t('Частину оплати повернено.'),
      default => (string) $this->t('Перевіряємо оплату… Якщо підтвердження затримується, лист надійде після обробки.'),
    };
  }

}
