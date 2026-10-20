<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Form\TrendFilterForm;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\SnapshotHistory;
use Drupal\lifebalance\Service\TrendGeometry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shows how one sector's score moved over time.
 */
final class TrendController extends ControllerBase {

  public function __construct(
    protected SnapshotRepository $snapshotRepository,
    protected SectorRepository $sectorRepository,
    protected SnapshotHistory $snapshotHistory,
    protected TrendGeometry $trendGeometry,
    protected Clock $clock,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Trend page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $catalog = $this->sectorRepository->loadAll();
    $sector = $this->sector($catalog);
    $period = $this->period();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-trend-page']],
      '#cache' => $this->personalCache($uid),
      '#attached' => ['library' => ['lifebalance/trend']],
    ];
    $sectors = [];
    foreach ($catalog as $id => $item) {
      $sectors[$id] = (string) $item->label();
    }
    $build['form'] = $this->formBuilder()->getForm(
      TrendFilterForm::class,
      $sectors,
      $sector,
      $period,
    );
    if ($this->snapshotRepository->countForOwner($uid) < 2) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Для динаміки потрібно щонайменше два зрізи.'),
      ];
      $build['history'] = [
        '#type' => 'link',
        '#title' => $this->t('До історії'),
        '#url' => Url::fromRoute('lifebalance.history'),
      ];
      return $build;
    }

    $since = $period === 'all' ? NULL : $this->clock->now() - ((int) $period * 86400);
    $snapshots = array_reverse($this->snapshotRepository->loadRecentForOwner(
      $uid,
      TrendGeometry::LIMIT,
      $since,
    ));
    $points = [];
    $rows = [];
    $numeric = 0;
    foreach ($snapshots as $snapshot) {
      $value = $this->scoreFor($snapshot, $sector);
      $date = $this->snapshotHistory->formatCreated($snapshot, $account);
      $label = $value === NULL ? '' : $value . ' · ' . $date;
      $points[] = [
        'time' => $snapshot->getCreatedTime(),
        'value' => $value,
        'label' => $label,
      ];
      $rows[] = [$date, $value === NULL ? '—' : (string) $value];
      if ($value !== NULL) {
        $numeric++;
      }
    }
    $points = $this->trendGeometry->limit($points);
    if ($numeric < 2) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('У цьому періоді менше двох оцінок цього сектора.'),
      ];
    }
    else {
      $geometry = $this->trendGeometry->build($points);
      $encoded = json_encode($this->jsonPoints($points), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
      $build['chart'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['lifebalance-trend'],
          'data-lifebalance-trend' => 'true',
          'data-points' => is_string($encoded) ? $encoded : '[]',
        ],
        'svg' => [
          '#theme' => 'lifebalance_trend',
          '#geometry' => $geometry,
        ],
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['lifebalance-summary']],
      '#header' => [$this->t('Дата'), $this->t('Оцінка')],
      '#rows' => $rows,
    ];
    return $build;
  }

  /**
   * A known sector id, or the first sector in display order.
   *
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $catalog
   *   Sectors keyed by id.
   */
  private function sector(array $catalog): string {
    $requested = $this->queryString('sector');
    if (isset($catalog[$requested])) {
      return $requested;
    }
    $first = array_key_first($catalog);
    return is_string($first) ? $first : '';
  }

  /**
   * Allows all, 90, or 365. Anything else is the full range.
   */
  private function period(): string {
    $period = $this->queryString('period');
    return $period === '90' || $period === '365' ? $period : 'all';
  }

  /**
   * The sector score, or NULL when that snapshot has no such sector.
   */
  private function scoreFor(Snapshot $snapshot, string $sector): ?int {
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === $sector) {
        return $item->getScore();
      }
    }
    return NULL;
  }

  /**
   * Points the chart script may read. Labels are dates and scores only.
   *
   * @param list<array{time: int, value: int|null, label?: string}> $points
   *   Chart points.
   *
   * @return list<array{time: int, value: int, label: string}>
   *   Points that have a score.
   */
  private function jsonPoints(array $points): array {
    $encoded = [];
    foreach ($points as $point) {
      if ($point['value'] === NULL) {
        continue;
      }
      $encoded[] = [
        'time' => $point['time'],
        'value' => $point['value'],
        'label' => $point['label'] ?? (string) $point['value'],
      ];
    }
    return $encoded;
  }

  /**
   * One query parameter, ignoring repeated keys.
   */
  private function queryString(string $key): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    $value = $request->query->all()[$key] ?? '';
    return is_string($value) ? $value : '';
  }

  /**
   * Cache metadata for a personal trend.
   *
   * @return array<string, mixed>
   *   Cache contexts and the owner's list tag.
   */
  private function personalCache(int $uid): array {
    return [
      'contexts' => ['user', 'timezone', 'url.query_args'],
      'tags' => ['lifebalance_snapshot_list:' . $uid],
    ];
  }

}
