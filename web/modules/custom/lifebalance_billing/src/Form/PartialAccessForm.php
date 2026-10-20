<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Drupal\lifebalance_billing\Service\OrderActions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manual decision after a partial refund. The order status is left as it is.
 */
final class PartialAccessForm extends OrderActionFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_partial_access';
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
      '#value' => $this->t('Частину оплати повернено. Доступ можна завершити вручну. Зрізи і цілі залишаються.'),
    ];
    if (!$this->actions->partialIsOpen($order)) {
      $form['blocked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Для цього замовлення рішення вже не потрібне.'),
      ];
      return $form;
    }
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Причина'),
      '#maxlength' => 255,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Скасувати доступ'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $reason = $form_state->getValue('reason');
    $reason = is_string($reason) ? trim($reason) : '';
    if ($reason === '' || mb_strlen($reason) > 255) {
      $form_state->setErrorByName('reason', $this->t('Вкажіть причину.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reason = $form_state->getValue('reason');
    $reason = is_string($reason) ? trim($reason) : '';
    try {
      $this->actions->revokePartial($this->order(), $reason);
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Доступ не завершено.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Доступ із цієї оплати завершено.'));
    $form_state->setRedirectUrl(Url::fromRoute('lifebalance_billing.orders'));
  }

}
