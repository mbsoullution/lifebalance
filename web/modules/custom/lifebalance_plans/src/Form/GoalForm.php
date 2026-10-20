<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Repository\GoalRepository;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Status and checklist for one goal. The copied text stays as it was.
 */
final class GoalForm extends FormBase {

  public function __construct(
    protected GoalRepository $goals,
    protected GoalManager $manager,
    protected SectorRepository $sectors,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(GoalRepository::class),
      $container->get(GoalManager::class),
      $container->get(SectorRepository::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_plans_goal';
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
    $goal = $this->goal();
    $uid = (int) $this->currentUser()->id();
    $form['#cache'] = [
      'contexts' => ['user'],
      'tags' => [
        'lifebalance_goal_list:' . $uid,
        'lifebalance_goal:' . $goal->id(),
      ],
      'max-age' => 0,
    ];
    $sector = $this->sectors->loadActive()[$goal->getSectorId()] ?? NULL;
    $form['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $goal->getTitle(),
    ];
    $form['sector'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $sector === NULL ? $goal->getSectorId() : (string) $sector->label(),
    ];
    $form['instructions'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $goal->getInstructions(),
    ];
    $options = [];
    foreach (Goal::statusLabels() as $value => $label) {
      $options[$value] = $label;
    }
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Статус'),
      '#options' => $options,
      '#default_value' => $goal->getStatus(),
    ];
    $form['due'] = [
      '#type' => 'date',
      '#title' => $this->t('Термін'),
      '#default_value' => $goal->getDue() ?? '',
      '#description' => $this->t('Можна лишити порожнім.'),
    ];
    $step_options = [];
    $done = [];
    foreach ($goal->getSteps() as $index => $step) {
      $key = (string) $index;
      $step_options[$key] = $step->getText();
      if ($step->isDone()) {
        $done[] = $key;
      }
    }
    $form['steps'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Кроки'),
      '#options' => $step_options,
      '#default_value' => $done,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Оновити ціль'),
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
    $goal = $this->goal();
    $due = $form_state->getValue('due');
    $status = $this->manager->setStatus(
      $goal,
      (string) $form_state->getValue('status'),
      is_string($due) && $due !== '' ? $due : NULL,
    );
    if ($status->code !== GoalResult::SAVED || $status->goal === NULL) {
      $this->messenger()->addError($this->t('Ціль не оновлено.'));
      return;
    }
    $flags = [];
    $raw = $form_state->getValue('steps');
    $selected = is_array($raw) ? $raw : [];
    $count = count($status->goal->getSteps());
    for ($index = 0; $index < $count; $index++) {
      $key = (string) $index;
      $flags[] = ($selected[$key] ?? 0) === $key;
    }
    $steps = $this->manager->setSteps($status->goal, $flags);
    if ($steps->code !== GoalResult::SAVED) {
      $this->messenger()->addError($this->t('Кроки не оновлено.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Ціль оновлено.'));
  }

  /**
   * The goal in the URL, or 404 for anyone except the owner.
   */
  private function goal(): Goal {
    $uuid = $this->getRouteMatch()->getParameter('uuid');
    $goal = $this->goals->loadByUuidForOwner(
      is_string($uuid) ? $uuid : '',
      (int) $this->currentUser()->id(),
    );
    if (!$goal instanceof Goal) {
      throw new NotFoundHttpException();
    }
    $access = $goal->access('update', $this->currentUser(), TRUE);
    if (!$access->isAllowed()) {
      throw new NotFoundHttpException();
    }
    return $goal;
  }

}
