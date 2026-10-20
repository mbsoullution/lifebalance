<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Extends, shortens, or revokes one manual record.
 *
 * Payment records never reach this form. A compensation is a new grant.
 */
final class EntitlementOperationForm extends FormBase {

  public function __construct(
    protected EntitlementManager $manager,
    protected Clock $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_entitlement_' . $this->operation();
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
    $this->account();
    $this->record();
    $form['#cache'] = [
      'contexts' => ['user.permissions'],
      'max-age' => 0,
    ];
    $operation = $this->operation();
    if ($operation === 'extend') {
      $form['duration'] = [
        '#type' => 'radios',
        '#title' => $this->t('Продовжити на'),
        '#required' => TRUE,
        '#options' => [
          '7' => $this->t('7 днів'),
          '30' => $this->t('30 днів'),
          '365' => $this->t('365 днів'),
          'date' => $this->t('До дати'),
          'unlimited' => $this->t('Безстроково'),
        ],
      ];
      $form['until'] = [
        '#type' => 'date',
        '#title' => $this->t('Дата завершення'),
      ];
    }
    elseif ($operation === 'shorten') {
      $form['duration'] = [
        '#type' => 'radios',
        '#title' => $this->t('Нове завершення'),
        '#required' => TRUE,
        '#options' => [
          'now' => $this->t('Зараз'),
          'date' => $this->t('До дати'),
        ],
      ];
      $form['until'] = [
        '#type' => 'date',
        '#title' => $this->t('Дата завершення'),
      ];
      $form['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Не раніше ніж зараз і раніше за поточне завершення.'),
      ];
    }
    else {
      $form['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Скасування зупиняє цей запис. Інші записи не змінюються.'),
      ];
    }
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Причина'),
      '#required' => TRUE,
      '#required_error' => $this->t('Вкажіть причину.'),
      '#maxlength' => 255,
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => match ($operation) {
          'extend' => $this->t('Продовжити'),
          'shorten' => $this->t('Скоротити'),
          default => $this->t('Скасувати'),
        },
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
    $reason = trim((string) $form_state->getValue('reason'));
    if ($reason === '' || mb_strlen($reason) > 255) {
      $form_state->setErrorByName('reason', $this->t('Вкажіть причину.'));
    }
    if ((string) $form_state->getValue('duration') !== 'date') {
      return;
    }
    $ends = $this->manager->endOfKyivDate((string) $form_state->getValue('until'));
    if ($ends === NULL || $ends < $this->clock->now()) {
      $form_state->setErrorByName('until', $this->t('Дата має бути не раніше ніж зараз.'));
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
    $user = $this->account();
    $record = $this->record();
    $actor = $this->currentUser();
    $reason = trim((string) $form_state->getValue('reason'));
    try {
      $operation = $this->operation();
      if ($operation === 'extend') {
        $ends = $this->extendEnd($record, $form_state);
        $this->manager->extend($record, $ends, $reason, $actor);
      }
      elseif ($operation === 'shorten') {
        $ends = $this->shortenEnd($form_state);
        $this->manager->shorten($record, $ends, $reason, $actor);
      }
      else {
        $this->manager->revoke($record, $reason, $actor);
      }
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('Зміну не збережено. Перевірте дату і причину.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Зміну збережено.'));
    $form_state->setRedirect('lifebalance.admin_user', ['user' => $user->id()]);
  }

  /**
   * The new end for an extension.
   */
  private function extendEnd(Entitlement $record, FormStateInterface $form_state): ?int {
    $duration = (string) $form_state->getValue('duration');
    if ($duration === 'unlimited') {
      return NULL;
    }
    if ($duration === 'date') {
      return $this->manager->endOfKyivDate((string) $form_state->getValue('until'));
    }
    $current = $record->getEnds() ?? $this->clock->now();
    $base = max($current, $this->clock->now());
    return $this->manager->endOfDays($base + 1, (int) $duration);
  }

  /**
   * The new end for a shortening. Now is allowed.
   */
  private function shortenEnd(FormStateInterface $form_state): int {
    if ((string) $form_state->getValue('duration') === 'now') {
      return $this->clock->now();
    }
    return $this->manager->endOfKyivDate((string) $form_state->getValue('until')) ?? 0;
  }

  /**
   * Route operation, limited to the three manual changes.
   */
  private function operation(): string {
    $operation = $this->getRouteMatch()->getParameter('operation');
    $operation = is_string($operation) ? $operation : '';
    return in_array($operation, ['extend', 'shorten', 'revoke'], TRUE) ? $operation : 'revoke';
  }

  /**
   * The account from the route.
   */
  private function account(): UserInterface {
    $user = $this->getRouteMatch()->getParameter('user');
    if (!$user instanceof UserInterface || (int) $user->id() < 1) {
      throw new NotFoundHttpException();
    }
    return $user;
  }

  /**
   * The manual record from the route, when it belongs to the account.
   */
  private function record(): Entitlement {
    $user = $this->account();
    $record = $this->getRouteMatch()->getParameter('entitlement');
    if (!$record instanceof Entitlement || $record->getOwnerId() !== (int) $user->id()) {
      throw new NotFoundHttpException();
    }
    return $record;
  }

}
