<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\lifebalance\Service\TrialResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms the one 7-day trial. The post carries the form token.
 */
final class TrialConfirmForm extends FormBase {

  public function __construct(
    protected EntitlementManager $entitlements,
    protected EntitlementNotifier $notifier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntitlementManager::class),
      $container->get(EntitlementNotifier::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_trial_confirm';
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
    $form['#cache'] = [
      'contexts' => ['user'],
      'tags' => ['config:lifebalance.settings'],
      'max-age' => 0,
    ];
    $account = $this->currentUser();
    if (!$this->entitlements->trialAvailable($account)) {
      $form['blocked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->refusal($this->entitlements->trialBlock($account) ?? TrialResult::DISABLED),
      ];
      return $form;
    }
    $form['card'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Картка не потрібна. Списань немає.'),
    ];
    $form['length'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Ознайомлення триває 7 днів від підтвердження.'),
    ];
    $form['kept'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Збережені зрізи, відповіді, цілі й плани лишаються.'),
    ];
    $form['closed'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Нові розширені відповіді й новий план будуть недоступні.'),
    ];
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Підтверджую старт ознайомлення'),
      '#required' => TRUE,
      '#required_error' => $this->t('Підтвердьте старт ознайомлення.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Спробувати Premium 7 днів'),
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
    $result = $this->entitlements->startTrial($this->currentUser());
    if ($result->code === TrialResult::SAVED && $result->entitlement !== NULL) {
      $this->notifier->sendTrialStarted($result->entitlement);
      $this->messenger()->addStatus($this->t('Ознайомлення на 7 днів розпочато.'));
      $form_state->setRedirect('lifebalance.wheel');
      return;
    }
    $this->messenger()->addError($this->refusal($result->code));
    $form_state->setRebuild();
  }

  /**
   * Status text for a refused start.
   */
  private function refusal(string $code): string {
    return match ($code) {
      TrialResult::NO_SNAPSHOT => (string) $this->t('Спочатку збережіть зріз.'),
      TrialResult::USED => (string) $this->t('Ознайомлення вже використано.'),
      default => (string) $this->t('Ознайомлення зараз вимкнено.'),
    };
  }

}
