<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\EntitlementAdmin;
use Drupal\lifebalance\Service\EntitlementLog;

/**
 * GET filter for the entitlement log.
 */
final class EntitlementLogFilterForm extends FormBase {

  public function __construct(
    protected EntitlementLog $log,
    protected EntitlementAdmin $admin,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_entitlement_log_filter';
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
    $actor = $query->get('actor');
    $target = $query->get('target');
    $action = $query->get('action');
    $from = $query->get('from');
    $to = $query->get('to');
    $selected = is_string($action) && $this->log->allows($action) ? $action : '';
    $options = ['' => (string) $this->t('Будь-яка')];
    foreach ($this->log->actions() as $key) {
      $options[$key] = $this->admin->actionLabel($key);
    }

    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [AdminUserFilterForm::class, 'stripBuildId'];
    $form['actor'] = [
      '#type' => 'number',
      '#title' => $this->t('Хто, UID'),
      '#default_value' => is_string($actor) ? $actor : '',
      '#min' => 1,
    ];
    $form['target'] = [
      '#type' => 'number',
      '#title' => $this->t('Кому, UID'),
      '#default_value' => is_string($target) ? $target : '',
      '#min' => 1,
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Що'),
      '#options' => $options,
      '#default_value' => $selected,
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
        '#value' => $this->t('Знайти'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
