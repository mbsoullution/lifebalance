<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementAdmin;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Grants a manual Premium record. Payment records are not created here.
 */
final class EntitlementGrantForm extends FormBase {

  public function __construct(
    protected EntitlementManager $manager,
    protected EntitlementAdmin $admin,
    protected EntitlementNotifier $notifier,
    protected Clock $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_entitlement_grant';
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
    $user = $this->account();
    $form['#cache'] = [
      'contexts' => ['user.permissions'],
      'tags' => ['lifebalance_entitlement:' . $user->id()],
      'max-age' => 0,
    ];
    if ($this->admin->hadTrial((int) $user->id())) {
      $form['trial_warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Ознайомлювальний доступ уже надавали. Повторне надання можливе.'),
      ];
    }
    $form['duration'] = [
      '#type' => 'radios',
      '#title' => $this->t('Тривалість'),
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
      '#states' => [
        'visible' => [
          ':input[name="duration"]' => ['value' => 'date'],
        ],
      ],
    ];
    $form['source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Джерело'),
      '#required' => TRUE,
      '#options' => [
        Entitlement::SOURCE_ADMIN => $this->t('Надання'),
        Entitlement::SOURCE_TRIAL => $this->t('Ознайомлення'),
      ],
      '#default_value' => Entitlement::SOURCE_ADMIN,
    ];
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Причина'),
      '#required' => TRUE,
      '#required_error' => $this->t('Вкажіть причину.'),
      '#maxlength' => 255,
    ];
    $form['notify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Повідомити'),
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Надати'),
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
    if ($ends === NULL || $ends <= $this->clock->now()) {
      $form_state->setErrorByName('until', $this->t('Дата має бути пізніше за сьогодні.'));
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
    $actor = $this->currentUser();
    $starts = $this->clock->now();
    $duration = (string) $form_state->getValue('duration');
    $ends = match ($duration) {
      '7', '30', '365' => $this->manager->endOfDays($starts, (int) $duration),
      'date' => $this->manager->endOfKyivDate((string) $form_state->getValue('until')),
      default => NULL,
    };
    $source = (string) $form_state->getValue('source');
    try {
      $entitlement = $this->manager->grant(
        $user,
        $source,
        $starts,
        $ends,
        trim((string) $form_state->getValue('reason')),
        $actor,
        (bool) $form_state->getValue('notify'),
      );
    }
    catch (\InvalidArgumentException) {
      $this->messenger()->addError($this->t('Доступ не надано. Перевірте дату і причину.'));
      return;
    }
    $this->notifier->sendGrant($entitlement);
    $this->messenger()->addStatus($this->t('Доступ надано.'));
    $form_state->setRedirect('lifebalance.admin_user', ['user' => $user->id()]);
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

}
