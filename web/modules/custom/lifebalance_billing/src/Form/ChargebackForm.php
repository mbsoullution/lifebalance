<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Drupal\lifebalance_billing\Service\OrderActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records a chargeback and revokes the access from that payment.
 */
final class ChargebackForm extends OrderActionFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_chargeback';
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
      '#value' => $this->t('Позначка спору завершує доступ із цієї оплати. Зрізи і цілі залишаються.'),
    ];
    if ($order->getStatus() !== PaymentOrder::STATUS_PAID) {
      $form['blocked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Це замовлення не можна позначити спором.'),
      ];
      return $form;
    }
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Позначити спір'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->actions->chargeback($this->order());
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Спір не позначено.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Спір позначено, доступ завершено.'));
    $form_state->setRedirectUrl(Url::fromRoute('lifebalance_billing.orders'));
  }

}
