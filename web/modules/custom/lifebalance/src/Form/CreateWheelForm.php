<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\WheelCreation;

/**
 * Creates the wheel once. A later submit keeps the original time.
 */
final class CreateWheelForm extends FormBase {

  public function __construct(
    protected WheelCreation $wheelCreation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_create_wheel';
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
    $form['#cache']['contexts'][] = 'user';
    $form['#cache']['max-age'] = 0;
    $form['intro'] = [
      '#markup' => $this->t('Після цього з’явиться колесо з восьми сфер.'),
    ];
    $form['kept'] = [
      '#markup' => $this->t('Час створення зберігається один раз.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Створити моє колесо'),
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
    $this->wheelCreation->ensure($this->currentUser());
    $this->messenger()->addStatus($this->t('Колесо створено.'));
    $form_state->setRedirect('lifebalance.wheel');
  }

}
