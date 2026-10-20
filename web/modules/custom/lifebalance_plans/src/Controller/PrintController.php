<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\lifebalance\Service\SnapshotHistory;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\PlanManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A print view of the latest snapshot, the current plan, and its goals.
 *
 * The browser saves it as PDF. This page does not generate a file.
 */
final class PrintController extends ControllerBase {

  public function __construct(
    protected SnapshotRepository $snapshots,
    protected SnapshotHistory $snapshotHistory,
    protected RadarGeometry $radarGeometry,
    protected GoalManager $goals,
    protected PlanManager $plans,
    protected SectorRepository $sectors,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SnapshotRepository::class),
      $container->get(SnapshotHistory::class),
      $container->get(RadarGeometry::class),
      $container->get(GoalManager::class),
      $container->get(PlanManager::class),
      $container->get(SectorRepository::class),
    );
  }

  /**
   * Wheel, latest snapshot, the active plan, and goals with their steps.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $build = [
      '#cache' => [
        'contexts' => ['user', 'timezone'],
        'tags' => [
          'lifebalance_snapshot_list:' . $uid,
          'lifebalance_goal_list:' . $uid,
          'lifebalance_plan_list:' . $uid,
        ],
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => ['lifebalance_plans/print'],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-print-actions']],
        'button' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Друк / Зберегти як PDF'),
          '#attributes' => [
            'type' => 'button',
            'data-lifebalance-print' => 'true',
          ],
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Без скриптів відкрийте друк у браузері й оберіть «Зберегти як PDF».'),
        ],
      ],
    ];
    $snapshot = $this->latest($uid);
    if (!$snapshot instanceof Snapshot) {
      $build['empty_snapshot'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Зрізу ще немає.'),
      ];
    }
    else {
      $build['snapshot'] = $this->snapshot($snapshot);
    }
    $plan = $this->plans->active($account);
    $on_plan = [];
    if ($plan instanceof Plan) {
      $build['plan'] = $this->planBlock($plan);
      foreach ($this->plans->goals($plan) as $goal) {
        $on_plan[(int) $goal->id()] = TRUE;
      }
    }
    $goals = [];
    foreach ($this->goals->forView($account, 'active') as $goal) {
      if (!isset($on_plan[(int) $goal->id()])) {
        $goals[] = $goal;
      }
    }
    $build['goals_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Активні цілі'),
    ];
    if ($goals === []) {
      $build['empty_goals'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Активних цілей ще немає.'),
      ];
      return $build;
    }
    foreach ($goals as $goal) {
      $build['goals'][$goal->id()] = $this->goal($goal);
    }
    return $build;
  }

  /**
   * The active 30-day plan and the goals copied into it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function planBlock(Plan $plan): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-print-plan']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('План на 30 днів'),
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('План на 30 днів до @date включно. Залишилось днів: @days.', [
          '@date' => $this->formatEnd($plan),
          '@days' => $this->plans->daysLeft($plan),
        ]),
      ],
      'sectors' => [
        '#theme' => 'item_list',
        '#items' => $this->sectorLabels($plan->getFocusSectors()),
      ],
    ];
    $goals = $this->plans->goals($plan);
    if ($goals === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('У цьому плані цілей ще немає.'),
      ];
      return $build;
    }
    foreach ($goals as $goal) {
      $build['goals'][$goal->id()] = $this->goal($goal);
    }
    return $build;
  }

  /**
   * End date in Europe/Kyiv.
   */
  private function formatEnd(Plan $plan): string {
    $zone = new \DateTimeZone('Europe/Kyiv');
    $date = (new \DateTimeImmutable('@' . $plan->getEnds()))->setTimezone($zone);
    return $date->format('d.m.Y');
  }

  /**
   * Sector labels in the order stored on the plan.
   *
   * @param list<string> $ids
   *   Sector IDs.
   *
   * @return list<string>
   *   Labels.
   */
  private function sectorLabels(array $ids): array {
    $active = $this->sectors->loadActive();
    $labels = [];
    foreach ($ids as $id) {
      $sector = $active[$id] ?? NULL;
      $labels[] = $sector === NULL ? $id : (string) $sector->label();
    }
    return $labels;
  }

  /**
   * Newest snapshot for this person, or NULL.
   */
  private function latest(int $uid): ?Snapshot {
    if ($uid < 1) {
      return NULL;
    }
    foreach ($this->snapshots->loadForOwner($uid) as $snapshot) {
      return $snapshot;
    }
    return NULL;
  }

  /**
   * Radar and the score table for one snapshot.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function snapshot(Snapshot $snapshot): array {
    $rows = $this->snapshotHistory->scoreRows($snapshot);
    $chart = [];
    foreach ($rows as $row) {
      $chart[] = [
        'label' => $row['sector'],
        'color' => $row['color'],
        'value' => (int) $row['score'],
      ];
    }
    $build = [
      'meta' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('@date · @mode', [
          '@date' => $this->snapshotHistory->formatCreated($snapshot, $this->currentUser()),
          '@mode' => (string) $this->snapshotHistory->modeLabel($snapshot),
        ]),
      ],
      'average' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Середнє: @average', [
          '@average' => $this->snapshotHistory->average($snapshot),
        ]),
      ],
      'radar' => [
        '#theme' => 'lifebalance_radar',
        '#geometry' => $this->radarGeometry->build($chart),
      ],
      'scores' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Сектор'),
          $this->t('Оцінка'),
          $this->t('Орієнтир'),
        ],
        '#rows' => [],
      ],
    ];
    foreach ($rows as $row) {
      $build['scores']['#rows'][] = [
        $row['sector'],
        $row['score'],
        $row['guide'],
      ];
    }
    $note = $snapshot->getNote();
    if ($note !== '') {
      $build['note'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $note,
      ];
    }
    return $build;
  }

  /**
   * One active goal and its checklist.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function goal(Goal $goal): array {
    $items = [];
    foreach ($goal->getSteps() as $step) {
      $items[] = $step->isDone()
        ? $this->t('Виконано: @text', ['@text' => $step->getText()])
        : $this->t('Ще ні: @text', ['@text' => $step->getText()]);
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-print-goal']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $goal->getTitle(),
      ],
      'instructions' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $goal->getInstructions(),
      ],
      'steps' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

}
