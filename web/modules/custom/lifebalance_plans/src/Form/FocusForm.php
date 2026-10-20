<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Service\FocusResult;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Drupal\lifebalance_plans\Service\PlanManager;
use Drupal\lifebalance_plans\Service\PlanResult;
use Drupal\lifebalance_plans\Service\PreviewResult;
use Drupal\lifebalance_plans\Service\RecommendationEngine;
use Drupal\lifebalance_plans\Service\Suggestion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The person chooses one or two sectors and sees reflection prompts.
 *
 * Premium can copy a prompt into a goal. The free preview cannot be stored.
 */
final class FocusForm extends FormBase {

  public function __construct(
    protected SectorRepository $sectorRepository,
    protected EntitlementCheckerInterface $entitlementChecker,
    protected RecommendationEngine $engine,
    protected GoalManager $goals,
    protected PlanManager $plans,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SectorRepository::class),
      $container->get(EntitlementCheckerInterface::class),
      $container->get(RecommendationEngine::class),
      $container->get(GoalManager::class),
      $container->get(PlanManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_plans_focus';
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
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $this->cache($form, $uid);
    $snapshot = $this->engine->latestAnswered($account);
    if (!$snapshot instanceof Snapshot) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Щоб побачити підказки, спочатку дайте відповіді в опитувальнику.'),
      ];
      $form['link'] = [
        '#type' => 'link',
        '#title' => $this->t('Перейти до опитувальника'),
        '#url' => Url::fromRoute('lifebalance.wheel'),
      ];
      return $form;
    }

    $form['lead'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Підказки для саморефлексії на основі ваших відповідей.'),
    ];
    $premium = $this->entitlementChecker->hasFeatureAccess($account, 'recommendations');
    if (!$premium) {
      $this->renderPreview($form, $this->engine->preview($account));
      return $form;
    }

    $lowest = $this->lowestSector($snapshot);
    $form['sectors'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Сфери'),
      '#tree' => TRUE,
      '#description' => $this->t('Оберіть одну або дві сфери.'),
    ];
    foreach ($this->sectorRepository->loadActive() as $id => $sector) {
      $form['sectors'][$id] = [
        '#type' => 'checkbox',
        '#title' => $sector->label(),
        '#return_value' => $id,
        '#description' => $id === $lowest
          ? $this->t('Найнижча оцінка в останньому зрізі.')
          : '',
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Показати підказки'),
    ];
    if ($form_state->isSubmitted() && !$form_state->hasAnyErrors()) {
      $this->renderFocus($form, $this->engine->focus(
        $account,
        $this->selected($form_state),
        $this->goals->activeRecommendationKeys($account),
      ));
    }
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
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && isset($trigger['#recommendation_key'])) {
      return;
    }
    if (!$this->entitlementChecker->hasFeatureAccess($this->currentUser(), 'recommendations')) {
      return;
    }
    $count = count($this->selected($form_state));
    if ($count < 1 || $count > 2) {
      $form_state->setErrorByName('sectors', $this->t('Оберіть одну або дві сфери.'));
    }
    if (is_array($trigger) && !empty($trigger['#plan_start'])) {
      $chosen = count($this->chosenKeys($form_state));
      if ($chosen < 1 || $chosen > PlanManager::START_LIMIT) {
        $form_state->setErrorByName('plan_keys', $this->t('Оберіть від однієї до трьох підказок.'));
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
    $trigger = $form_state->getTriggeringElement();
    if (is_array($trigger) && !empty($trigger['#plan_start'])) {
      $result = $this->plans->start(
        $this->currentUser(),
        $this->selected($form_state),
        $this->chosenKeys($form_state),
      );
      if ($result->code === PlanResult::SAVED) {
        $this->messenger()->addStatus($this->t('План на 30 днів розпочато.'));
        $form_state->setRedirect('lifebalance_plans.plan');
        return;
      }
      $this->messenger()->addError($this->planRefusal($result->code));
      $form_state->setRebuild(TRUE);
      return;
    }
    $key = is_array($trigger) ? ($trigger['#recommendation_key'] ?? NULL) : NULL;
    if (is_string($key) && $key !== '') {
      $result = $this->goals->fromRecommendation($this->currentUser(), $key);
      if ($result->code === GoalResult::SAVED) {
        $this->messenger()->addStatus($this->t('Ціль додано до плану.'));
      }
      else {
        $this->messenger()->addError($this->refusal($result->code));
      }
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * Chosen sector IDs, in catalog order.
   *
   * @return list<string>
   *   Sector IDs.
   */
  private function selected(FormStateInterface $form_state): array {
    $value = $form_state->getValue('sectors');
    if (!is_array($value)) {
      return [];
    }
    $chosen = [];
    foreach ($value as $id => $checked) {
      if (is_string($checked) && $checked !== '') {
        $chosen[] = $checked;
      }
      elseif (is_string($id) && $checked === $id) {
        $chosen[] = $id;
      }
    }
    $order = array_keys($this->sectorRepository->loadActive());
    $chosen = array_values(array_unique($chosen));
    usort($chosen, static function (string $a, string $b) use ($order): int {
      $left = array_search($a, $order, TRUE);
      $right = array_search($b, $order, TRUE);
      return (is_int($left) ? $left : PHP_INT_MAX) <=> (is_int($right) ? $right : PHP_INT_MAX);
    });
    return $chosen;
  }

  /**
   * Sector with the lowest score. Ties use the catalog order.
   */
  private function lowestSector(Snapshot $snapshot): ?string {
    $lowestId = NULL;
    $lowestScore = NULL;
    $order = array_keys($this->sectorRepository->loadActive());
    foreach ($snapshot->getScores() as $item) {
      $id = $item->getSectorId();
      $score = $item->getScore();
      if ($lowestScore === NULL || $score < $lowestScore) {
        $lowestScore = $score;
        $lowestId = $id;
        continue;
      }
      if ($score === $lowestScore) {
        $left = array_search($id, $order, TRUE);
        $right = array_search($lowestId, $order, TRUE);
        if (is_int($left) && is_int($right) && $left < $right) {
          $lowestId = $id;
        }
      }
    }
    return $lowestId;
  }

  /**
   * Free preview. Nothing on the page stores it.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\lifebalance_plans\Service\PreviewResult $result
   *   The single preview, if one matched.
   */
  private function renderPreview(array &$form, PreviewResult $result): void {
    if ($result->support) {
      $form['support'] = $this->support($result->supportText);
    }
    if ($result->suggestion === NULL) {
      return;
    }
    $form['preview'] = $this->suggestion($result->suggestion, FALSE);
    $form['demo'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('У розширеному доступі — персональний план і кроки.'),
    ];
  }

  /**
   * Full result for the submitted sectors.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\lifebalance_plans\Service\FocusResult $result
   *   Suggestions for the chosen sectors.
   */
  private function renderFocus(array &$form, FocusResult $result): void {
    if ($result->support) {
      $form['support'] = $this->support($result->supportText);
    }
    $labels = [];
    foreach ($this->sectorRepository->loadActive() as $id => $sector) {
      $labels[$id] = (string) $sector->label();
    }
    foreach ($result->sectors as $sector) {
      $section = [
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $labels[$sector->sectorId] ?? $sector->sectorId,
        ],
      ];
      if ($sector->items === []) {
        $section['empty'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Для цієї сфери поки немає готових рекомендацій. Ви можете додати власну ціль.'),
        ];
        $section['own'] = [
          '#type' => 'link',
          '#title' => $this->t('Додати власну ціль'),
          '#url' => Url::fromRoute('lifebalance_plans.plan_add'),
        ];
      }
      foreach ($sector->items as $item) {
        // Catalog keys contain dots, which Form API treats as nesting.
        $section[] = $this->suggestion($item, TRUE, $this->canStartPlan());
      }
      $form['results'][$sector->sectorId] = $section;
    }
    if ($this->canStartPlan()) {
      $form['plan_keys'] = ['#tree' => TRUE];
      $form['start_plan'] = [
        '#type' => 'submit',
        '#value' => $this->t('Почати план на 30 днів'),
        '#name' => 'start_plan',
        '#plan_start' => TRUE,
      ];
    }
  }

  /**
   * One suggestion. Health text is the standard note, not a diagnosis.
   *
   * @param \Drupal\lifebalance_plans\Service\Suggestion $suggestion
   *   The prompt to show.
   * @param bool $canSave
   *   Whether the goals feature may copy it into a goal.
   * @param bool $forPlan
   *   Whether the suggestion can be included in a new 30-day plan.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function suggestion(Suggestion $suggestion, bool $canSave, bool $forPlan = FALSE): array {
    $build = [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $suggestion->title,
      ],
      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $suggestion->body,
      ],
    ];
    if ($suggestion->steps !== []) {
      $build['steps'] = [
        '#theme' => 'item_list',
        '#items' => $suggestion->steps,
      ];
    }
    if ($suggestion->healthNote) {
      $build['health'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Це не медична порада. Якщо симптоми турбують, зверніться до лікаря.'),
      ];
    }
    if ($forPlan) {
      $build['include'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Включити в план на 30 днів'),
        '#return_value' => $suggestion->key,
        '#parents' => ['plan_keys', substr(hash('sha256', $suggestion->key), 0, 16)],
      ];
    }
    if ($canSave) {
      $build['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('Додати до плану'),
        '#name' => 'add_' . substr(hash('sha256', $suggestion->key), 0, 16),
        '#recommendation_key' => $suggestion->key,
        '#limit_validation_errors' => [],
      ];
    }
    return $build;
  }

  /**
   * Checked recommendation keys for a new plan.
   *
   * @return list<string>
   *   Catalog keys.
   */
  private function chosenKeys(FormStateInterface $form_state): array {
    $value = $form_state->getValue('plan_keys');
    if (!is_array($value)) {
      return [];
    }
    $keys = [];
    foreach ($value as $checked) {
      if (is_string($checked) && $checked !== '' && $checked !== '0') {
        $keys[] = $checked;
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * A new plan needs the plan feature and no active plan.
   */
  private function canStartPlan(): bool {
    $account = $this->currentUser();
    if (!$this->entitlementChecker->hasFeatureAccess($account, 'plan_30')) {
      return FALSE;
    }
    return !$this->plans->active($account) instanceof Plan;
  }

  /**
   * Message when a 30-day plan cannot start.
   */
  private function planRefusal(string $code): string {
    return (string) match ($code) {
      PlanResult::PREMIUM => $this->t('Новий план доступний з розширеним доступом.'),
      PlanResult::ACTIVE => $this->t('План на 30 днів уже триває.'),
      PlanResult::LIMIT => $this->t('Активних цілей уже @limit. Завершіть або скасуйте одну, щоб почати план.', [
        '@limit' => $this->goals->limit(),
      ]),
      default => $this->t('План зараз не можна почати.'),
    };
  }

  /**
   * Message when a prompt cannot become a goal.
   */
  private function refusal(string $code): string {
    return (string) match ($code) {
      GoalResult::PREMIUM => $this->t('Нові цілі доступні з розширеним доступом.'),
      GoalResult::LIMIT => $this->t('Активних цілей уже @limit. Завершіть або скасуйте одну, щоб додати нову.', [
        '@limit' => $this->goals->limit(),
      ]),
      GoalResult::DUPLICATE => $this->t('Ця підказка вже є серед активних цілей.'),
      GoalResult::SENSITIVE => $this->t('Для цієї відповіді поради не додаються.'),
      default => $this->t('Цю підказку зараз не можна додати.'),
    };
  }

  /**
   * Support block. The text comes from configuration.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function support(string $text): array {
    $build = [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Підтримка'),
      ],
    ];
    if ($text !== '') {
      $build['text'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $text,
      ];
    }
    return $build;
  }

  /**
   * Personal and entitlement cache metadata. The page is not stored.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param int $uid
   *   The current user ID.
   */
  private function cache(array &$form, int $uid): void {
    $meta = $this->entitlementChecker->getCacheability($this->currentUser());
    $tags = $meta->getCacheTags();
    $tags[] = 'lifebalance_snapshot_list:' . $uid;
    $tags[] = 'lifebalance_goal_list:' . $uid;
    $tags[] = 'config:lifebalance_plans.settings';
    $form['#cache'] = [
      'contexts' => $meta->getCacheContexts(),
      'tags' => $tags,
      'max-age' => 0,
    ];
  }

}
