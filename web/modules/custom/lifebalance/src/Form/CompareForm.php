<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Chooses two snapshots. The comparison itself is read-only.
 */
final class CompareForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_compare';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, string> $options
   *   Snapshot UUID to a date label.
   * @param string $selected_a
   *   Selected UUID for snapshot A.
   * @param string $selected_b
   *   Selected UUID for snapshot B.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $options = [], string $selected_a = '', string $selected_b = ''): array {
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#action'] = Url::fromRoute('lifebalance.compare')->toString();
    $form['#after_build'][] = [static::class, 'dropInternal'];
    $form['#attributes']['class'][] = 'lifebalance-compare-form';
    $form['a'] = [
      '#type' => 'select',
      '#title' => $this->t('Зріз A'),
      '#options' => $options,
      '#default_value' => $selected_a,
    ];
    $form['b'] = [
      '#type' => 'select',
      '#title' => $this->t('Зріз B'),
      '#options' => $options,
      '#default_value' => $selected_b,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Порівняти'),
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
