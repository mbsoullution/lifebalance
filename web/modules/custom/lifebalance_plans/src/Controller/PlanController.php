<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Form\CurrentPlanForm;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\PlanManager;
use Drupal\lifebalance_plans\Service\ReassessmentPrompt;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the current person's goals. There is no admin listing.
 */
final class PlanController extends ControllerBase {

  public function __construct(
    protected GoalManager $goals,
    protected SectorRepository $sectors,
    protected EntitlementCheckerInterface $entitlementChecker,
    protected ReassessmentPrompt $prompts,
    protected PlanManager $plans,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(GoalManager::class),
      $container->get(SectorRepository::class),
      $container->get(EntitlementCheckerInterface::class),
      $container->get(ReassessmentPrompt::class),
      $container->get(PlanManager::class),
    );
  }

  /**
   * Active goals.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function active(): array {
    $build = $this->page('active');
    $build['current_plan'] = $this->formBuilder()->getForm(CurrentPlanForm::class);
    $build['current_plan']['#weight'] = -20;
    return $build;
  }

  /**
   * Active goals due within 30 days, plus goals with no term.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function days30(): array {
    return $this->page('days30');
  }

  /**
   * Active goals due within 90 days, plus goals with no term.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function days90(): array {
    return $this->page('days90');
  }

  /**
   * Done and cancelled goals.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function closed(): array {
    return $this->page('closed');
  }

  /**
   * One tab of the plan.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function page(string $view): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $build = [
      '#cache' => $this->personalCache($uid),
      '#attached' => [
        'library' => ['lifebalance_plans/print'],
      ],
      'tabs' => $this->tabs($view),
      'print' => [
        '#type' => 'link',
        '#title' => $this->t('Версія для друку'),
        '#url' => Url::fromRoute('lifebalance_plans.print'),
      ],
    ];
    $prompts = $this->reassessment();
    if ($prompts !== []) {
      $build['prompts'] = $prompts;
    }
    $plan_reassess = $this->planReassess();
    if ($plan_reassess !== []) {
      $build['plan_reassess'] = $plan_reassess;
    }
    if ($this->entitlementChecker->hasPremium($account)) {
      $build['add'] = [
        '#type' => 'link',
        '#title' => $this->t('Додати власну ціль'),
        '#url' => Url::fromRoute('lifebalance_plans.plan_add'),
        '#weight' => -10,
      ];
    }
    $goals = $this->goals->forView($account, $view);
    if ($goals === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('У цьому переліку цілей ще немає.'),
      ];
      $build['focus'] = [
        '#type' => 'link',
        '#title' => $this->t('Підказки для саморефлексії'),
        '#url' => Url::fromRoute('lifebalance_plans.focus'),
      ];
      return $build;
    }
    $labels = [];
    foreach ($this->sectors->loadActive() as $id => $sector) {
      $labels[$id] = (string) $sector->label();
    }
    $items = [];
    foreach ($goals as $goal) {
      $items[] = $this->summary($goal, $labels);
    }
    $build['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
    return $build;
  }

  /**
   * Tab links. The theme does not print local tasks.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function tabs(string $current): array {
    $links = [
      'active' => ['lifebalance_plans.plan', $this->t('Активні')],
      'days30' => ['lifebalance_plans.plan_30', $this->t('30 днів')],
      'days90' => ['lifebalance_plans.plan_90', $this->t('90 днів')],
      'closed' => ['lifebalance_plans.plan_closed', $this->t('Виконані/скасовані')],
    ];
    $items = [];
    foreach ($links as $view => $link) {
      $items[] = [
        '#type' => 'link',
        '#title' => $link[1],
        '#url' => Url::fromRoute($link[0]),
        '#attributes' => $view === $current ? ['class' => ['is-active']] : [],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['lifebalance-plan-tabs']],
    ];
  }

  /**
   * One banner per sector whose first goal is old enough.
   *
   * @return array<string, mixed>
   *   A render array, or empty when nothing is due.
   */
  private function reassessment(): array {
    $items = [];
    foreach ($this->prompts->forAccount($this->currentUser()) as $prompt) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('Минуло @days днів від цілі у сфері «@sector». Переоцінити сферу?', [
          '@days' => $prompt['days'],
          '@sector' => $prompt['label'],
        ]),
        '#url' => Url::fromRoute('lifebalance.questionnaire', [
          'sector' => $prompt['sector_id'],
        ]),
      ];
    }
    if ($items === []) {
      return [];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['lifebalance-reassess']],
    ];
  }

  /**
   * Banner after a plan is completed, until the next snapshot.
   *
   * @return array<string, mixed>
   *   A render array, or empty when no invitation is open.
   */
  private function planReassess(): array {
    $labels = [];
    foreach ($this->sectors->loadActive() as $id => $sector) {
      $labels[$id] = (string) $sector->label();
    }
    $items = [];
    foreach ($this->plans->invitationSectors($this->currentUser()) as $sector_id) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('Переоцінити сферу «@sector»?', [
          '@sector' => $labels[$sector_id] ?? $sector_id,
        ]),
        '#url' => Url::fromRoute('lifebalance.questionnaire', [
          'sector' => $sector_id,
        ]),
      ];
    }
    if ($items === []) {
      return [];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['lifebalance-reassess']],
    ];
  }

  /**
   * One goal in the list.
   *
   * @param \Drupal\lifebalance_plans\Entity\Goal $goal
   *   Goal to summarize.
   * @param array<string, string> $labels
   *   Sector labels keyed by id.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function summary(Goal $goal, array $labels): array {
    $status = Goal::statusLabels()[$goal->getStatus()] ?? $goal->getStatus();
    $due = $goal->getDue();
    $meta = $due === NULL
      ? $this->t('@sector · @status', [
        '@sector' => $labels[$goal->getSectorId()] ?? $goal->getSectorId(),
        '@status' => $status,
      ])
      : $this->t('@sector · @status · до @due', [
        '@sector' => $labels[$goal->getSectorId()] ?? $goal->getSectorId(),
        '@status' => $status,
        '@due' => $due,
      ]);
    return [
      'link' => [
        '#type' => 'link',
        '#title' => $goal->getTitle(),
        '#url' => Url::fromRoute('lifebalance_plans.goal', [
          'uuid' => $goal->uuid(),
        ]),
      ],
      'meta' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $meta,
      ],
    ];
  }

  /**
   * Personal cache metadata. The page is not stored.
   *
   * @return array<string, mixed>
   *   Cache metadata.
   */
  private function personalCache(int $uid): array {
    $meta = $this->entitlementChecker->getCacheability($this->currentUser());
    $tags = $meta->getCacheTags();
    $tags[] = 'lifebalance_goal_list:' . $uid;
    $tags[] = 'lifebalance_plan_list:' . $uid;
    return [
      'contexts' => $meta->getCacheContexts(),
      'tags' => $tags,
      'max-age' => 0,
    ];
  }

}
