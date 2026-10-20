<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Form\SnapshotNoteForm;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\lifebalance\Service\SnapshotHistory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists the current person's snapshots and shows one of them.
 */
final class HistoryController extends ControllerBase {

  /**
   * Snapshots on one history page.
   */
  private const PAGE_SIZE = 20;

  public function __construct(
    protected SnapshotRepository $snapshotRepository,
    protected SnapshotHistory $snapshotHistory,
    protected RadarGeometry $radarGeometry,
    protected PagerManagerInterface $pagerManager,
    protected EntitlementManager $entitlements,
  ) {}

  /**
   * History list for the current person.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $total = $this->snapshotRepository->countForOwner($uid);
    $build = [
      '#cache' => $this->personalCache($uid),
      '#attached' => ['library' => ['lifebalance/history']],
      'nav' => $this->analyticsLinks(),
    ];
    if ($total === 0) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Зрізів ще немає.'),
      ];
      $build['wheel'] = [
        '#type' => 'link',
        '#title' => $this->t('До колеса'),
        '#url' => Url::fromRoute('lifebalance.wheel'),
      ];
      return $build;
    }
    if ($this->entitlements->trialAvailable($account)) {
      $build['trial'] = [
        '#type' => 'link',
        '#title' => $this->t('Спробувати Premium 7 днів'),
        '#url' => Url::fromRoute('lifebalance.trial'),
      ];
    }

    $this->pagerManager->createPager($total, self::PAGE_SIZE);
    $page = $this->pagerManager->findPage();
    $build['list'] = [
      '#theme' => 'item_list',
      '#items' => [],
      '#attributes' => ['class' => ['lifebalance-history']],
    ];
    foreach ($this->snapshotRepository->loadPageForOwner($uid, $page, self::PAGE_SIZE) as $snapshot) {
      $build['list']['#items'][] = $this->summary($snapshot);
    }
    $build['pager'] = ['#type' => 'pager'];
    return $build;
  }

  /**
   * One snapshot: radar, scores, historical answers, note, and deletion.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function view(string $uuid): array {
    $snapshot = $this->owned($uuid);
    $uid = (int) $snapshot->getOwnerId();
    $rows = $this->snapshotHistory->scoreRows($snapshot);
    $chart = [];
    foreach ($rows as $row) {
      $chart[] = [
        'label' => $row['sector'],
        'color' => $row['color'],
        'value' => (int) $row['score'],
      ];
    }
    $cache = $this->personalCache($uid);
    foreach ($snapshot->getCacheTags() as $tag) {
      $cache['tags'][] = $tag;
    }
    $build = [
      '#cache' => $cache,
      '#attached' => ['library' => ['lifebalance/history']],
    ];
    $build['meta'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('@date · @mode · @catalog', [
        '@date' => $this->snapshotHistory->formatCreated($snapshot, $this->currentUser()),
        '@mode' => (string) $this->snapshotHistory->modeLabel($snapshot),
        '@catalog' => $this->snapshotHistory->catalogLabel($snapshot),
      ]),
    ];
    $build['average'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['lifebalance-average']],
      '#value' => $this->t('Середнє: @average', [
        '@average' => $this->snapshotHistory->average($snapshot),
      ]),
    ];
    $build['radar'] = [
      '#theme' => 'lifebalance_radar',
      '#geometry' => $this->radarGeometry->build($chart),
    ];
    $build['scores'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['lifebalance-summary']],
      '#header' => [
        $this->t('Сектор'),
        $this->t('Оцінка'),
        $this->t('Орієнтир'),
      ],
      '#rows' => [],
    ];
    foreach ($rows as $row) {
      $build['scores']['#rows'][] = [$row['sector'], $row['score'], $row['guide']];
    }
    $build['answers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-history-answers']],
    ];
    foreach ($this->snapshotHistory->answerRows($snapshot) as $delta => $row) {
      $build['answers'][$delta] = $this->answer($row);
    }
    $build['note'] = $this->formBuilder()->getForm(SnapshotNoteForm::class, (string) $snapshot->uuid());
    $build['delete'] = [
      '#type' => 'link',
      '#title' => $this->t('Видалити зріз'),
      '#url' => Url::fromRoute('lifebalance.snapshot_delete', [
        'uuid' => $snapshot->uuid(),
      ]),
    ];
    return $build;
  }

  /**
   * Page title for one snapshot.
   */
  public function title(string $uuid): string {
    // Breadcrumbs resolve this title for the parent path. Throwing here makes
    // the 404 page call the title again and exhausts memory.
    $snapshot = $this->snapshotRepository->loadByUuidForOwner($uuid, (int) $this->currentUser()->id());
    if (!$snapshot instanceof Snapshot) {
      return '';
    }
    return $this->snapshotHistory->formatCreated($snapshot, $this->currentUser());
  }

  /**
   * The current person's snapshot, or a 404 that hides other owners.
   */
  private function owned(string $uuid): Snapshot {
    $snapshot = $this->snapshotRepository->loadByUuidForOwner($uuid, (int) $this->currentUser()->id());
    if (!$snapshot instanceof Snapshot) {
      throw new NotFoundHttpException();
    }
    return $snapshot;
  }

  /**
   * Links to comparison and the trend.
   *
   * @return array<string, mixed>
   *   A short list of links.
   */
  private function analyticsLinks(): array {
    return [
      '#theme' => 'item_list',
      '#items' => [
        [
          '#type' => 'link',
          '#title' => $this->t('Порівняння'),
          '#url' => Url::fromRoute('lifebalance.compare'),
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Динаміка'),
          '#url' => Url::fromRoute('lifebalance.trend'),
        ],
      ],
      '#attributes' => ['class' => ['lifebalance-history-nav']],
      '#wrapper_attributes' => ['class' => ['lifebalance-history-nav']],
    ];
  }

  /**
   * One history-list entry.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function summary(Snapshot $snapshot): array {
    $excerpt = $this->snapshotHistory->excerpt($snapshot->getNote());
    $item = [
      'link' => [
        '#type' => 'link',
        '#title' => $this->snapshotHistory->formatCreated($snapshot, $this->currentUser()),
        '#url' => Url::fromRoute('lifebalance.snapshot', ['uuid' => $snapshot->uuid()]),
      ],
      'meta' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Середнє: @average · @mode · @catalog', [
          '@average' => $this->snapshotHistory->average($snapshot),
          '@mode' => (string) $this->snapshotHistory->modeLabel($snapshot),
          '@catalog' => $this->snapshotHistory->catalogLabel($snapshot),
        ]),
        '#attributes' => ['class' => ['lifebalance-average']],
      ],
    ];
    if ($excerpt !== '') {
      $item['note'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $excerpt,
      ];
    }
    return $item;
  }

  /**
   * One stored answer, using the historical wording.
   *
   * @param array{sector: string, text: string, help: string, answer: string, premium: bool} $row
   *   Display values.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function answer(array $row): array {
    $text = $row['text'] !== '' ? $row['text'] : (string) $this->t('Формулювання цієї ревізії недоступне.');
    $build = [
      '#type' => 'container',
      'sector' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $row['sector'],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $text,
      ],
    ];
    if ($row['premium']) {
      $build['tier'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Розширене твердження'),
      ];
    }
    if ($row['help'] !== '') {
      $build['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $row['help'],
      ];
    }
    $build['choice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $row['answer'],
    ];
    return $build;
  }

  /**
   * Cache metadata for a personal snapshot page.
   *
   * @return array<string, mixed>
   *   Cache contexts and the owner's list tag.
   */
  private function personalCache(int $uid): array {
    return [
      'contexts' => ['user', 'timezone', 'url.query_args:page'],
      'tags' => [
        'lifebalance_snapshot_list:' . $uid,
        'config:lifebalance.settings',
      ],
    ];
  }

}
