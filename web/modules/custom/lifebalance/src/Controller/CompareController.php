<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Form\CompareForm;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\lifebalance\Service\SnapshotDelta;
use Drupal\lifebalance\Service\SnapshotHistory;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Compares two snapshots that belong to the current person.
 */
final class CompareController extends ControllerBase {

  public function __construct(
    protected SnapshotRepository $snapshotRepository,
    protected SectorRepository $sectorRepository,
    protected SnapshotDelta $snapshotDelta,
    protected SnapshotHistory $snapshotHistory,
    protected RadarGeometry $radarGeometry,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Comparison page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-compare']],
      '#cache' => $this->personalCache($uid),
      '#attached' => ['library' => ['lifebalance/compare']],
    ];
    $requested_a = $this->queryString('a');
    $requested_b = $this->queryString('b');
    if ($requested_a !== '') {
      $this->owned($requested_a);
    }
    if ($requested_b !== '') {
      $this->owned($requested_b);
    }
    if ($this->snapshotRepository->countForOwner($uid) < 2) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Для порівняння потрібно щонайменше два зрізи.'),
      ];
      $build['history'] = [
        '#type' => 'link',
        '#title' => $this->t('До історії'),
        '#url' => Url::fromRoute('lifebalance.history'),
      ];
      return $build;
    }

    [$left, $right] = $this->pair($uid);
    $catalog = $this->sectorRepository->loadAll();
    $options = $this->options($uid, $account, [$left, $right]);
    $build['form'] = $this->formBuilder()->getForm(
      CompareForm::class,
      $options,
      (string) $left->uuid(),
      (string) $right->uuid(),
    );
    $build['legend'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('A — суцільна лінія і квадратні позначки. B — пунктир і круглі позначки.'),
    ];
    $build['meta_a'] = $this->meta('A', $left, $account);
    $build['meta_b'] = $this->meta('B', $right, $account);
    $score_rows = $this->snapshotDelta->rows(
      $this->scoreMap($left),
      $this->scoreMap($right),
      array_keys($catalog),
    );
    $build['radar'] = [
      '#theme' => 'lifebalance_radar',
      '#geometry' => $this->radarGeometry->overlay($this->chartSectors($score_rows, $catalog)),
    ];
    $build['scores'] = $this->table(
      [
        $this->t('Сектор'),
        $this->t('A'),
        $this->t('B'),
        $this->t('Δ'),
      ],
      $this->valueRows($score_rows, $catalog),
    );
    $guide = $this->guideSection($left, $right, $catalog);
    if ($guide !== []) {
      $build['guides'] = $guide;
    }
    $answers = $this->changedAnswers($left, $right, $catalog);
    if ($answers !== []) {
      $build['answers_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Відповіді, що змінились'),
      ];
      $build['answers'] = $answers;
    }
    return $build;
  }

  /**
   * Snapshot A and B. A missing UUID falls back to the two newest.
   *
   * @return array{0: \Drupal\lifebalance\Entity\Snapshot, 1: \Drupal\lifebalance\Entity\Snapshot}
   *   Older-or-chosen A, then B.
   */
  private function pair(int $uid): array {
    $recent = $this->snapshotRepository->loadRecentForOwner($uid, 2);
    $newest = $recent[0] ?? NULL;
    $previous = $recent[1] ?? NULL;
    if (!$newest instanceof Snapshot || !$previous instanceof Snapshot) {
      throw new NotFoundHttpException();
    }
    $a = $this->queryString('a');
    $b = $this->queryString('b');
    $left = $a === '' ? $previous : $this->owned($a);
    $right = $b === '' ? $newest : $this->owned($b);
    if (($a === '' || $b === '') && $left->uuid() === $right->uuid()) {
      $left = $left->uuid() === $previous->uuid() ? $newest : $previous;
    }
    return [$left, $right];
  }

  /**
   * The current person's snapshot, or a 404.
   */
  private function owned(string $uuid): Snapshot {
    if (preg_match('/^[0-9a-fA-F-]{1,64}$/', $uuid) !== 1) {
      throw new NotFoundHttpException();
    }
    $snapshot = $this->snapshotRepository->loadByUuidForOwner($uuid, (int) $this->currentUser()->id());
    if (!$snapshot instanceof Snapshot) {
      throw new NotFoundHttpException();
    }
    return $snapshot;
  }

  /**
   * Select labels for the person's recent snapshots.
   *
   * @param int $uid
   *   Owner id.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current person, for the timezone.
   * @param list<\Drupal\lifebalance\Entity\Snapshot> $extra
   *   Snapshots that must appear even outside the recent page.
   *
   * @return array<string, string>
   *   UUID to date.
   */
  private function options(int $uid, AccountInterface $account, array $extra): array {
    $options = [];
    $snapshots = $this->snapshotRepository->loadRecentForOwner($uid, 500);
    foreach ([...$extra, ...$snapshots] as $snapshot) {
      $uuid = (string) $snapshot->uuid();
      $options[$uuid] = $this->snapshotHistory->formatCreated($snapshot, $account);
    }
    return $options;
  }

  /**
   * Date, mode, and average for one side.
   *
   * @param string $side
   *   A or B.
   * @param \Drupal\lifebalance\Entity\Snapshot $snapshot
   *   The snapshot.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current person.
   *
   * @return array<string, mixed>
   *   A paragraph.
   */
  private function meta(string $side, Snapshot $snapshot, AccountInterface $account): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['lifebalance-average']],
      '#value' => $this->t('@side · @date · @mode · @catalog · Середнє: @average', [
        '@side' => $side,
        '@date' => $this->snapshotHistory->formatCreated($snapshot, $account),
        '@mode' => $this->snapshotHistory->modeLabel($snapshot),
        '@catalog' => $this->snapshotHistory->catalogLabel($snapshot),
        '@average' => $this->snapshotHistory->average($snapshot),
      ]),
    ];
  }

  /**
   * Guide table, or an explanation when the scales differ.
   *
   * @param \Drupal\lifebalance\Entity\Snapshot $left
   *   Snapshot A.
   * @param \Drupal\lifebalance\Entity\Snapshot $right
   *   Snapshot B.
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $catalog
   *   Sectors keyed by id.
   *
   * @return array<string, mixed>
   *   A render array, empty when there is nothing to say.
   */
  private function guideSection(Snapshot $left, Snapshot $right, array $catalog): array {
    $comparable = $this->snapshotDelta->guidesComparable(
      $left->getAssessmentMode(),
      $left->getFormulaVersion(),
      $right->getAssessmentMode(),
      $right->getFormulaVersion(),
    );
    if ($comparable) {
      $rows = $this->snapshotDelta->rows(
        $this->guideMap($left),
        $this->guideMap($right),
        array_keys($catalog),
      );
      return $this->table(
        [
          $this->t('Сектор'),
          $this->t('Орієнтир A'),
          $this->t('Орієнтир B'),
          $this->t('Δ'),
        ],
        $this->valueRows($rows, $catalog),
      );
    }
    $interesting = $left->getAssessmentMode() !== Snapshot::MODE_NONE
      || $right->getAssessmentMode() !== Snapshot::MODE_NONE
      || $left->getFormulaVersion() !== NULL
      || $right->getFormulaVersion() !== NULL;
    if (!$interesting) {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Орієнтири цих зрізів пораховані в різних режимах або за різними версіями формули, тому їх не зіставлено.'),
    ];
  }

  /**
   * Answers that changed inside one criterion revision.
   *
   * @param \Drupal\lifebalance\Entity\Snapshot $left
   *   Snapshot A.
   * @param \Drupal\lifebalance\Entity\Snapshot $right
   *   Snapshot B.
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $catalog
   *   Sectors keyed by id.
   *
   * @return array<string, mixed>
   *   A table, or an empty array.
   */
  private function changedAnswers(Snapshot $left, Snapshot $right, array $catalog): array {
    $changes = $this->snapshotDelta->changedAnswers(
      $this->answerItems($left),
      $this->answerItems($right),
    );
    if ($changes === []) {
      return [];
    }
    $storage = $this->entityTypeManager()->getStorage('lifebalance_criterion');
    if (!$storage instanceof RevisionableStorageInterface) {
      return [];
    }
    $sortable = [];
    foreach ($changes as $change) {
      $revision = $storage->loadRevision($change['revision_id']);
      $text = '';
      $weight = 0;
      $sector_weight = PHP_INT_MAX;
      if ($revision instanceof Criterion
        && (int) $revision->id() === $change['criterion_id']
        && (int) $revision->getRevisionId() === $change['revision_id']
      ) {
        $text = $revision->getText();
        $weight = $revision->getWeight();
        $sector = $catalog[$revision->getSectorId()] ?? NULL;
        $sector_weight = $sector instanceof Sector ? $sector->getWeight() : PHP_INT_MAX;
      }
      $sortable[] = [
        'text' => $text,
        'left' => $this->answerLabel($change['left']),
        'right' => $this->answerLabel($change['right']),
        'weight' => $weight,
        'sector_weight' => $sector_weight,
      ];
    }
    usort($sortable, static function (array $a, array $b): int {
      $sector = $a['sector_weight'] <=> $b['sector_weight'];
      return $sector !== 0 ? $sector : $a['weight'] <=> $b['weight'];
    });
    $rows = [];
    foreach ($sortable as $row) {
      $text = $row['text'] !== '' ? $row['text'] : (string) $this->t('Формулювання цієї ревізії недоступне.');
      $rows[] = [$text, $row['left'], $row['right']];
    }
    return $this->table([
      $this->t('Твердження'),
      $this->t('A'),
      $this->t('B'),
    ], $rows);
  }

  /**
   * Chart input in the same order as the score table.
   *
   * @param list<array{sector: string, left: int|null, right: int|null, delta: int|null}> $rows
   *   Score rows.
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $catalog
   *   Sectors keyed by id.
   *
   * @return list<array{label: string, color: string, left: int|null, right: int|null}>
   *   Overlay sectors.
   */
  private function chartSectors(array $rows, array $catalog): array {
    $chart = [];
    foreach ($rows as $row) {
      $sector = $catalog[$row['sector']] ?? NULL;
      $chart[] = [
        'label' => $sector instanceof Sector ? (string) $sector->label() : $row['sector'],
        'color' => $sector instanceof Sector ? $sector->getColor() : '#6b7280',
        'left' => $row['left'],
        'right' => $row['right'],
      ];
    }
    return $chart;
  }

  /**
   * Table cells for one delta row set.
   *
   * @param list<array{sector: string, left: int|null, right: int|null, delta: int|null}> $rows
   *   Delta rows.
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $catalog
   *   Sectors keyed by id.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string}>
   *   Plain-text cells.
   */
  private function valueRows(array $rows, array $catalog): array {
    $rendered = [];
    foreach ($rows as $row) {
      $sector = $catalog[$row['sector']] ?? NULL;
      $rendered[] = [
        $sector instanceof Sector ? (string) $sector->label() : $row['sector'],
        $this->cell($row['left']),
        $this->cell($row['right']),
        $this->snapshotDelta->formatDelta($row['delta']),
      ];
    }
    return $rendered;
  }

  /**
   * A values table. Cell text is escaped by the table renderer.
   *
   * @param list<\Drupal\Core\StringTranslation\TranslatableMarkup> $header
   *   Column titles.
   * @param list<array<int, string>> $rows
   *   Plain-text cells.
   *
   * @return array<string, mixed>
   *   A table.
   */
  private function table(array $header, array $rows): array {
    return [
      '#type' => 'table',
      '#attributes' => ['class' => ['lifebalance-summary']],
      '#header' => $header,
      '#rows' => $rows,
    ];
  }

  /**
   * Stored scores keyed by sector.
   *
   * @return array<string, int>
   *   Sector id to score.
   */
  private function scoreMap(Snapshot $snapshot): array {
    $map = [];
    foreach ($snapshot->getScores() as $item) {
      $map[$item->getSectorId()] = $item->getScore();
    }
    return $map;
  }

  /**
   * Displayed guides keyed by sector. NULL means no guide.
   *
   * @return array<string, int|null>
   *   Sector id to a 0–10 guide.
   */
  private function guideMap(Snapshot $snapshot): array {
    $map = [];
    foreach ($snapshot->getScores() as $item) {
      $guide = $item->getGuide();
      $map[$item->getSectorId()] = $guide === NULL ? NULL : intdiv($guide, 10);
    }
    return $map;
  }

  /**
   * Answer triples for the delta service.
   *
   * @return list<array{criterion_id: int, revision_id: int, answer: string}>
   *   Stored answers.
   */
  private function answerItems(Snapshot $snapshot): array {
    $items = [];
    foreach ($snapshot->getAnswers() as $item) {
      $items[] = [
        'criterion_id' => $item->getCriterionId(),
        'revision_id' => $item->getCriterionRevisionId(),
        'answer' => $item->getAnswer(),
      ];
    }
    return $items;
  }

  /**
   * Ukrainian label for a stored answer.
   */
  private function answerLabel(string $answer): string {
    return match ($answer) {
      'yes' => (string) $this->t('Так'),
      'partial' => (string) $this->t('Частково'),
      'no' => (string) $this->t('Ні'),
      'na' => (string) $this->t('Не стосується'),
      default => '',
    };
  }

  /**
   * A number, or an em dash when the sector is missing.
   */
  private function cell(?int $value): string {
    return $value === NULL ? '—' : (string) $value;
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
   * Cache metadata for a personal comparison.
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
