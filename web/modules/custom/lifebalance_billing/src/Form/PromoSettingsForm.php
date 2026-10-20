<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Service\PromoReservations;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns the introductory price on and shows the slot counters.
 */
final class PromoSettingsForm extends FormBase {

  public function __construct(
    protected PromoReservations $promos,
    protected EntitlementManager $entitlements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PromoReservations::class),
      $container->get(EntitlementManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_promo_settings';
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
    $settings = $this->promos->settings();
    $form['#cache']['max-age'] = 0;
    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Акція увімкнена'),
      '#default_value' => $settings['active'] === 1,
    ];
    $form['slot_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Ліміт місць'),
      '#default_value' => $settings['slot_limit'],
      '#min' => 1,
      '#max' => 100000,
      '#required' => TRUE,
    ];
    $form['starts'] = [
      '#type' => 'date',
      '#title' => $this->t('Початок'),
      '#default_value' => $this->day($settings['starts']),
    ];
    $form['ends'] = [
      '#type' => 'date',
      '#title' => $this->t('Кінець'),
      '#default_value' => $this->day($settings['ends']),
    ];
    $form['paid'] = [
      '#type' => 'item',
      '#title' => $this->t('Оплачено місць'),
      '#markup' => (string) $settings['paid_count'],
    ];
    $form['reserved'] = [
      '#type' => 'item',
      '#title' => $this->t('Зарезервовано зараз'),
      '#markup' => (string) $settings['reserved'],
    ];
    $form['actions'] = ['#type' => 'actions'];
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
    $starts = $form_state->getValue('starts');
    $ends = $form_state->getValue('ends');
    $starts = is_string($starts) ? $starts : '';
    $ends = is_string($ends) ? $ends : '';
    if ($starts !== '' && $ends !== '' && $ends < $starts) {
      $form_state->setErrorByName('ends', $this->t('Кінець раніше за початок.'));
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
    $starts = $form_state->getValue('starts');
    $ends = $form_state->getValue('ends');
    $limit = $form_state->getValue('slot_limit');
    $this->promos->saveSettings(
      (bool) $form_state->getValue('active'),
      is_numeric($limit) ? (int) $limit : 100,
      $this->startOf($starts),
      $this->endOf($ends),
    );
    $this->messenger()->addStatus($this->t('Акцію збережено.'));
  }

  /**
   * Y-m-d in Europe/Kyiv, or an empty string.
   *
   * @param int $timestamp
   *   A unix instant, or 0.
   */
  private function day(int $timestamp): string {
    if ($timestamp < 1) {
      return '';
    }
    $zone = new \DateTimeZone('Europe/Kyiv');
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format('Y-m-d');
  }

  /**
   * Midnight of a Y-m-d value, or 0.
   *
   * @param mixed $value
   *   The date element value.
   */
  private function startOf(mixed $value): int {
    if (!is_string($value) || $value === '') {
      return 0;
    }
    $zone = new \DateTimeZone('Europe/Kyiv');
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    return $date === FALSE ? 0 : $date->getTimestamp();
  }

  /**
   * End of a Y-m-d value, or 0.
   *
   * @param mixed $value
   *   The date element value.
   */
  private function endOf(mixed $value): int {
    if (!is_string($value) || $value === '') {
      return 0;
    }
    return $this->entitlements->endOfKyivDate($value) ?? 0;
  }

}
