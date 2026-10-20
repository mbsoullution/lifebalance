<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Turns premium criteria on or off for the whole site.
 *
 * The formula version is not edited here.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return list<string>
   *   Editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return ['lifebalance.settings'];
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
    $form['premium_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Увімкнути розширене оцінювання'),
      '#default_value' => (bool) $this->config('lifebalance.settings')->get('premium_enabled'),
    ];
    $form['trial_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Дозволити ознайомлення на 7 днів'),
      '#description' => $this->t('Після першого зрізу. За замовчуванням вимкнено.'),
      '#default_value' => (bool) $this->config('lifebalance.settings')->get('trial_enabled'),
    ];
    $measurement_id = $this->config('lifebalance.settings')->get('ga_measurement_id');
    $form['ga_measurement_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ідентифікатор Google Analytics'),
      '#description' => $this->t('Ідентифікатор потоку GA4, наприклад G-ABC123XYZ. Порожнє поле вимикає лічильник. Рахуються лише публічні сторінки.'),
      '#default_value' => is_string($measurement_id) ? $measurement_id : '',
      '#size' => 20,
      '#element_validate' => [[static::class, 'validateMeasurementId']],
    ];
    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Зберегти');
    return $form;
  }

  /**
   * Normalizes the measurement id and rejects anything else.
   *
   * @param array<string, mixed> $element
   *   The text field.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateMeasurementId(array &$element, FormStateInterface $form_state): void {
    $id = $form_state->getValue('ga_measurement_id');
    $id = is_string($id) ? strtoupper(trim($id)) : '';
    $form_state->setValue('ga_measurement_id', $id);
    if ($id !== '' && preg_match('/^G-[A-Z0-9]{4,32}$/', $id) !== 1) {
      $form_state->setErrorByName(
        'ga_measurement_id',
        t('Ідентифікатор має виглядати як G-XXXXXXXX.'),
      );
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
    $id = $form_state->getValue('ga_measurement_id');
    $this->config('lifebalance.settings')
      ->set('premium_enabled', (bool) $form_state->getValue('premium_enabled'))
      ->set('trial_enabled', (bool) $form_state->getValue('trial_enabled'))
      ->set('ga_measurement_id', is_string($id) ? $id : '')
      ->save();
    $this->messenger()->addStatus($this->t('Налаштування збережено.'));
  }

}
