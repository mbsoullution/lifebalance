<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Entity\Criterion;

/**
 * The support text shown later for sensitive criteria. The owner writes it.
 */
final class SupportBlockForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_plans_support_block';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   Config object names.
   */
  protected function getEditableConfigNames(): array {
    return ['lifebalance_plans.settings'];
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
    $config = $this->config('lifebalance_plans.settings');
    $stored = $config->get('support_block');
    $form['support_block'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Блок підтримки'),
      '#description' => $this->t('Текст для чутливих критеріїв. Його побачить людина замість порад. Сюди можна додати контакти перевірених організацій.'),
      '#default_value' => is_string($stored) ? $stored : '',
      '#rows' => 8,
    ];
    $keys = $config->get('sensitive_criteria');
    $lines = [];
    if (is_array($keys)) {
      foreach ($keys as $key) {
        if (is_string($key) && $key !== '') {
          $lines[] = $key;
        }
      }
    }
    $limit = $config->get('active_goal_limit');
    $form['active_goal_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Ліміт активних цілей'),
      '#description' => $this->t('Скільки цілей зі статусом «Заплановано» або «У процесі» може бути одночасно. Від 1 до 10.'),
      '#default_value' => is_numeric($limit) ? (int) $limit : 10,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];
    $form['sensitive_criteria'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Чутливі критерії'),
      '#description' => $this->t('Ключі критеріїв, по одному на рядок. Якщо відповідь «Ні», поради для цього критерію не показуються.'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 4,
    ];
    return parent::buildForm($form, $form_state);
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
    $limit = $form_state->getValue('active_goal_limit');
    if (!is_numeric($limit) || (int) $limit < 1 || (int) $limit > 10) {
      $form_state->setErrorByName('active_goal_limit', $this->t('Ліміт активних цілей має бути від 1 до 10.'));
    }
    $raw = $form_state->getValue('sensitive_criteria');
    $text = is_string($raw) ? $raw : '';
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $key = trim($line);
      if ($key !== '' && !preg_match(Criterion::KEY_PATTERN, $key)) {
        $form_state->setErrorByName('sensitive_criteria', $this->t('Ключ критерію має складатися з малих латинських літер, цифр, крапки, дефіса або підкреслення.'));
        return;
      }
    }
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
    $value = $form_state->getValue('support_block');
    $limit = $form_state->getValue('active_goal_limit');
    $raw = $form_state->getValue('sensitive_criteria');
    $keys = [];
    $text = is_string($raw) ? $raw : '';
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $key = trim($line);
      if ($key !== '') {
        $keys[] = $key;
      }
    }
    $this->config('lifebalance_plans.settings')
      ->set('support_block', is_string($value) ? trim($value) : '')
      ->set('sensitive_criteria', array_values(array_unique($keys)))
      ->set('active_goal_limit', is_numeric($limit) ? (int) $limit : 10)
      ->save();
    $this->messenger()->addStatus($this->t('Налаштування збережено.'));
  }

}
