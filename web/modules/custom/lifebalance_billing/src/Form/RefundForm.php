<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Drupal\lifebalance_billing\Service\MinorMoney;
use Drupal\lifebalance_billing\Service\OrderActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks the provider to return the whole payment.
 */
final class RefundForm extends OrderActionFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_refund';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get(OrderRepository::class),
      $container->get(OrderActions::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $order = $this->order();
    $form['#cache']['contexts'][] = 'user';
    $form['#cache']['max-age'] = 0;
    $form['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Повне повернення @price грн за «@plan». Часткове повернення тут не оформлюється.', [
        '@price' => MinorMoney::format($order->getAmountMinor()),
        '@plan' => (string) $order->get('plan_label')->value,
      ]),
    ];
    if ($order->getStatus() !== PaymentOrder::STATUS_PAID || $order->getInvoiceId() === '') {
      $form['blocked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Це замовлення не можна повернути звідси.'),
      ];
      return $form;
    }
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Повернути'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $order = $this->order();
    try {
      $result = $this->actions->refund($order);
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Повернення не прийнято.'));
      return;
    }
    $fresh = $this->orders->loadByUuid($order->uuid());
    if ($result === 'applied' && $fresh instanceof PaymentOrder && $fresh->getStatus() === PaymentOrder::STATUS_REFUNDED) {
      $this->messenger()->addStatus($this->t('Оплату повернено.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Повернення надіслано. Статус оновиться після підтвердження.'));
    }
    $form_state->setRedirectUrl(Url::fromRoute('lifebalance_billing.orders'));
  }

}
