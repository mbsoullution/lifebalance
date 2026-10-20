<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Repository\SectorRepository;

/**
 * Edits sector labels, hints, scale captions, order, color, and status.
 *
 * The machine ID is shown and cannot be changed. Sectors cannot be deleted.
 */
final class SectorSettingsForm extends FormBase {

  public function __construct(
    protected SectorRepository $sectorRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_sector_settings';
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
    $form['help'] = [
      '#markup' => $this->t('Ідентифікатор не змінюється. Сектор можна лише вимкнути.'),
    ];
    $form['sectors'] = [
      '#tree' => TRUE,
    ];

    foreach ($this->sectorRepository->loadAll() as $id => $sector) {
      $form['sectors'][$id] = [
        '#type' => 'details',
        '#title' => $sector->label(),
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      $form['sectors'][$id]['id'] = [
        '#type' => 'item',
        '#title' => $this->t('Ідентифікатор'),
        '#markup' => $id,
      ];
      $form['sectors'][$id]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Назва'),
        '#default_value' => $sector->label(),
        '#required' => TRUE,
        '#maxlength' => 255,
      ];
      $form['sectors'][$id]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Підказка'),
        '#default_value' => $sector->getDescription(),
        '#required' => TRUE,
        '#rows' => 2,
      ];
      $form['sectors'][$id]['scale_low'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Підпис для 0'),
        '#default_value' => $sector->getScaleLow(),
        '#maxlength' => 255,
      ];
      $form['sectors'][$id]['scale_high'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Підпис для 10'),
        '#default_value' => $sector->getScaleHigh(),
        '#maxlength' => 255,
      ];
      $form['sectors'][$id]['weight'] = [
        '#type' => 'number',
        '#title' => $this->t('Порядок'),
        '#default_value' => $sector->getWeight(),
      ];
      $form['sectors'][$id]['color'] = [
        '#type' => 'color',
        '#title' => $this->t('Колір'),
        '#default_value' => $sector->getColor(),
        '#required' => TRUE,
      ];
      $form['sectors'][$id]['status'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Увімкнено'),
        '#default_value' => $sector->status(),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Зберегти'),
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
    $sectors = $form_state->getValue('sectors');
    if (!is_array($sectors)) {
      return;
    }
    foreach ($sectors as $id => $values) {
      if (!is_array($values)) {
        continue;
      }
      $color = $values['color'] ?? '';
      if (!is_string($color) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $form_state->setErrorByName('sectors][' . $id . '][color', $this->t('Колір має бути у форматі #RRGGBB.'));
      }
      if (!isset($values['weight']) || !is_numeric($values['weight'])) {
        $form_state->setErrorByName('sectors][' . $id . '][weight', $this->t('Порядок має бути числом.'));
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
    $submitted = $form_state->getValue('sectors');
    if (!is_array($submitted)) {
      return;
    }
    foreach ($this->sectorRepository->loadAll() as $id => $sector) {
      if (!isset($submitted[$id]) || !is_array($submitted[$id])) {
        continue;
      }
      $values = $submitted[$id];
      $sector
        ->set('label', (string) $values['label'])
        ->set('description', (string) $values['description'])
        ->set('scale_low', trim((string) ($values['scale_low'] ?? '')))
        ->set('scale_high', trim((string) ($values['scale_high'] ?? '')))
        ->set('weight', (int) $values['weight'])
        ->set('color', (string) $values['color'])
        ->setStatus((bool) $values['status'])
        ->save();
    }
    $this->messenger()->addStatus($this->t('Сектори збережено.'));
  }

}
