<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Service\OrderAdminList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET filter for the finance order list.
 */
final class OrderFilterForm extends FormBase {

  public function __construct(
    protected OrderAdminList $orders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get(OrderAdminList::class),
    );
    $form->setStringTranslation($container->get('string_translation'));
    $form->setRequestStack($container->get('request_stack'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_order_filter';
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
   *   The filter form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $status = $query->get('status');
    $plan = $query->get('plan');
    $from = $query->get('from');
    $to = $query->get('to');
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'stripBuildId'];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Статус'),
      '#options' => ['' => (string) $this->t('Усі')] + array_map('strval', PaymentOrder::statusLabels()),
      '#default_value' => is_string($status) ? $status : '',
    ];
    $form['plan'] = [
      '#type' => 'select',
      '#title' => $this->t('План'),
      '#options' => ['' => (string) $this->t('Усі')] + $this->orders->planOptions(),
      '#default_value' => is_string($plan) ? $plan : '',
    ];
    $form['from'] = [
      '#type' => 'date',
      '#title' => $this->t('Від'),
      '#default_value' => is_string($from) ? $from : '',
    ];
    $form['to'] = [
      '#type' => 'date',
      '#title' => $this->t('До'),
      '#default_value' => is_string($to) ? $to : '',
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Показати'),
      ],
    ];
    return $form;
  }

  /**
   * Drops Drupal form ids from the query string.
   *
   * @param array<string, mixed> $form
   *   The built form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state. Unused.
   *
   * @return array<string, mixed>
   *   The form without build ids.
   */
  public static function stripBuildId(array $form, FormStateInterface $form_state): array {
    unset($form['form_id'], $form['form_build_id'], $form['form_token']);
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
