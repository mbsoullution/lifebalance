<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Filters the trend by sector and period. Nothing is saved.
 */
final class TrendFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_trend_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, string> $sectors
   *   Sector id to label.
   * @param string $sector
   *   Selected sector id.
   * @param string $period
   *   Selected period: all, 90, or 365.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $sectors = [], string $sector = '', string $period = 'all'): array {
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#action'] = Url::fromRoute('lifebalance.trend')->toString();
    $form['#after_build'][] = [static::class, 'dropInternal'];
    $form['sector'] = [
      '#type' => 'select',
      '#title' => $this->t('Сектор'),
      '#options' => $sectors,
      '#default_value' => $sector,
    ];
    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('Період'),
      '#options' => [
        'all' => $this->t('Увесь час'),
        '90' => $this->t('90 днів'),
        '365' => $this->t('365 днів'),
      ],
      '#default_value' => $period,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Показати'),
    ];
    return $form;
  }

  /**
   * Drops Drupal form ids from the query string.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   *
   * @return array<string, mixed>
   *   The form without internal elements.
   */
  public static function dropInternal(array $form): array {
    unset($form['form_build_id'], $form['form_token'], $form['form_id']);
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
