<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A goal the person writes. Premium is required.
 */
final class OwnGoalForm extends FormBase {

  public function __construct(
    protected SectorRepository $sectors,
    protected EntitlementCheckerInterface $entitlementChecker,
    protected GoalManager $goals,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SectorRepository::class),
      $container->get(EntitlementCheckerInterface::class),
      $container->get(GoalManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_plans_own_goal';
  }

  /**
   * Premium is required to add a goal.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $meta = $this->entitlementChecker->getCacheability($account);
    $allowed = $this->entitlementChecker->hasFeatureAccess($account, 'goals');
    return AccessResult::allowedIf($allowed)
      ->cachePerUser()
      ->addCacheableDependency($meta);
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
    $uid = (int) $this->currentUser()->id();
    $meta = $this->entitlementChecker->getCacheability($this->currentUser());
    $tags = $meta->getCacheTags();
    $tags[] = 'lifebalance_goal_list:' . $uid;
    $form['#cache'] = [
      'contexts' => $meta->getCacheContexts(),
      'tags' => $tags,
      'max-age' => 0,
    ];
    $options = [];
    foreach ($this->sectors->loadActive() as $id => $sector) {
      $options[$id] = (string) $sector->label();
    }
    $form['sector'] = [
      '#type' => 'select',
      '#title' => $this->t('Сфера'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Назва'),
      '#maxlength' => 80,
      '#required' => TRUE,
    ];
    $form['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Опис'),
      '#required' => TRUE,
      '#rows' => 4,
    ];
    $form['steps'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Кроки'),
      '#tree' => TRUE,
      '#description' => $this->t('Від одного до пʼяти коротких кроків.'),
    ];
    for ($index = 0; $index < 5; $index++) {
      $form['steps'][$index] = [
        '#type' => 'textfield',
        '#title' => $this->t('Крок @number', ['@number' => $index + 1]),
        '#maxlength' => 255,
        '#required' => $index === 0,
      ];
    }
    $form['due'] = [
      '#type' => 'date',
      '#title' => $this->t('Термін'),
      '#description' => $this->t('Можна лишити порожнім.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Додати ціль'),
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
    $steps = $form_state->getValue('steps');
    $texts = [];
    if (is_array($steps)) {
      foreach ($steps as $step) {
        if (is_string($step)) {
          $texts[] = $step;
        }
      }
    }
    $due = $form_state->getValue('due');
    $result = $this->goals->createOwn(
      $this->currentUser(),
      (string) $form_state->getValue('sector'),
      (string) $form_state->getValue('title'),
      (string) $form_state->getValue('instructions'),
      $texts,
      is_string($due) && $due !== '' ? $due : NULL,
    );
    if ($result->code === GoalResult::SAVED && $result->goal !== NULL) {
      $this->messenger()->addStatus($this->t('Ціль додано до плану.'));
      $form_state->setRedirect('lifebalance_plans.goal', [
        'uuid' => $result->goal->uuid(),
      ]);
      return;
    }
    $this->messenger()->addError($this->failure($result->code));
  }

  /**
   * Message for a refused goal.
   */
  private function failure(string $code): string {
    return (string) match ($code) {
      GoalResult::PREMIUM => $this->t('Нові цілі доступні з розширеним доступом.'),
      GoalResult::LIMIT => $this->t('Активних цілей уже @limit. Завершіть або скасуйте одну, щоб додати нову.', [
        '@limit' => $this->goals->limit(),
      ]),
      default => $this->t('Ціль не збережено. Перевірте назву, опис і кроки.'),
    };
  }

}
