<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\lifebalance\Service\SnapshotHistory;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only wheels for the site owner.
 *
 * The route permission is not granted to any role. Entity access stays
 * owner-only, so a history address still hides another person's snapshot.
 */
final class AdminWheelController extends ControllerBase {

  public function __construct(
    private readonly SnapshotRepository $snapshots,
    private readonly SnapshotHistory $history,
    private readonly RadarGeometry $radar,
  ) {}

  /**
   * Saved wheels of one account, newest first.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function wheel(UserInterface $user): array {
    $uid = $this->accountId($user);
    $items = $this->snapshots->loadForOwner($uid);
    $build = [
      '#cache' => $this->wheelCache($uid),
      '#attached' => ['library' => ['lifebalance/history']],
      'back' => $this->cardLink($user),
    ];
    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Зрізів ще немає.'),
      ];
      return $build;
    }
    $build['list'] = [
      '#theme' => 'item_list',
      '#items' => [],
      '#attributes' => ['class' => ['lifebalance-history']],
    ];
    foreach ($items as $snapshot) {
      $build['list']['#items'][] = $this->summary($user, $snapshot);
    }
    $build['current'] = $this->snapshotView($items[0]);
    return $build;
  }

  /**
   * One saved wheel. A foreign UUID is a 404.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function snapshot(UserInterface $user, string $uuid): array {
    $uid = $this->accountId($user);
    $snapshot = $this->snapshots->loadByUuidForOwner($uuid, $uid);
    if (!$snapshot instanceof Snapshot) {
      throw new NotFoundHttpException();
    }
    return [
      '#cache' => $this->wheelCache($uid, $snapshot),
      '#attached' => ['library' => ['lifebalance/history']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('До коліс'),
        '#url' => Url::fromRoute('lifebalance.admin_user_wheel', [
          'user' => $uid,
        ]),
      ],
      'current' => $this->snapshotView($snapshot),
    ];
  }

  /**
   * Title for the wheel list.
   */
  public function title(UserInterface $user): string {
    if ((int) $user->id() < 1) {
      return '';
    }
    return (string) $this->t('Колесо — @name', [
      '@name' => $user->getDisplayName(),
    ]);
  }

  /**
   * Title for one snapshot. Empty when it is not this account's wheel.
   */
  public function snapshotTitle(UserInterface $user, string $uuid): string {
    $uid = (int) $user->id();
    if ($uid < 1) {
      return '';
    }
    $snapshot = $this->snapshots->loadByUuidForOwner($uuid, $uid);
    if (!$snapshot instanceof Snapshot) {
      return '';
    }
    return $this->history->formatCreated($snapshot, $this->currentUser());
  }

  /**
   * Rejects the anonymous placeholder before any snapshot is loaded.
   */
  private function accountId(UserInterface $user): int {
    $uid = (int) $user->id();
    if ($uid < 1) {
      throw new NotFoundHttpException();
    }
    return $uid;
  }

  /**
   * Link back to the aggregate card.
   *
   * @return array<string, mixed>
   *   A link render element.
   */
  private function cardLink(UserInterface $user): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('До картки'),
      '#url' => Url::fromRoute('lifebalance.admin_user', [
        'user' => $user->id(),
      ]),
    ];
  }

  /**
   * One list entry. The note stays on the wheel itself.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function summary(UserInterface $user, Snapshot $snapshot): array {
    return [
      'link' => [
        '#type' => 'link',
        '#title' => $this->history->formatCreated($snapshot, $this->currentUser()),
        '#url' => Url::fromRoute('lifebalance.admin_user_snapshot', [
          'user' => $user->id(),
          'uuid' => $snapshot->uuid(),
        ]),
      ],
      'meta' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Середнє: @average · @mode · @catalog', [
          '@average' => $this->history->average($snapshot),
          '@mode' => (string) $this->history->modeLabel($snapshot),
          '@catalog' => $this->history->catalogLabel($snapshot),
        ]),
        '#attributes' => ['class' => ['lifebalance-average']],
      ],
    ];
  }

  /**
   * Radar, scores, answers, and the note. Nothing here can be edited.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function snapshotView(Snapshot $snapshot): array {
    $rows = $this->history->scoreRows($snapshot);
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
        '#value' => $this->t('@date · @mode · @catalog', [
          '@date' => $this->history->formatCreated($snapshot, $this->currentUser()),
          '@mode' => (string) $this->history->modeLabel($snapshot),
          '@catalog' => $this->history->catalogLabel($snapshot),
        ]),
      ],
      'average' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['lifebalance-average']],
        '#value' => $this->t('Середнє: @average', [
          '@average' => $this->history->average($snapshot),
        ]),
      ],
      'radar' => [
        '#theme' => 'lifebalance_radar',
        '#geometry' => $this->radar->build($chart),
      ],
      'scores' => [
        '#type' => 'table',
        '#attributes' => ['class' => ['lifebalance-summary']],
        '#header' => [
          $this->t('Сектор'),
          $this->t('Оцінка'),
          $this->t('Орієнтир'),
        ],
        '#rows' => [],
      ],
    ];
    foreach ($rows as $row) {
      $build['scores']['#rows'][] = [$row['sector'], $row['score'], $row['guide']];
    }
    $build['answers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-history-answers']],
    ];
    foreach ($this->history->answerRows($snapshot) as $delta => $row) {
      $build['answers'][$delta] = $this->answer($row);
    }
    $note = $snapshot->getNote();
    if ($note !== '') {
      $build['note'] = [
        '#type' => 'inline_template',
        '#template' => '<p class="lifebalance-note">{{ note }}</p>',
        '#context' => ['note' => $note],
      ];
    }
    return $build;
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
    $text = $row['text'] !== ''
      ? $row['text']
      : (string) $this->t('Формулювання цієї ревізії недоступне.');
    $template = '<div><p>{{ sector }}</p>'
      . '{% if premium %}<p>{{ tier }}</p>{% endif %}'
      . '<p>{{ text }}</p>'
      . '{% if help %}<p>{{ help }}</p>{% endif %}'
      . '<p>{{ answer }}</p></div>';
    return [
      '#type' => 'inline_template',
      '#template' => $template,
      '#context' => [
        'sector' => $row['sector'],
        'premium' => $row['premium'],
        'tier' => (string) $this->t('Розширене твердження'),
        'text' => $text,
        'help' => $row['help'],
        'answer' => $row['answer'],
      ],
    ];
  }

  /**
   * Cache metadata for another person's wheel.
   *
   * @return array<string, mixed>
   *   Cache contexts and the owner's list tag.
   */
  private function wheelCache(int $uid, ?Snapshot $snapshot = NULL): array {
    $tags = ['lifebalance_snapshot_list:' . $uid];
    if ($snapshot instanceof Snapshot) {
      foreach ($snapshot->getCacheTags() as $tag) {
        $tags[] = $tag;
      }
    }
    return [
      'contexts' => ['user', 'timezone'],
      'max-age' => 0,
      'tags' => $tags,
    ];
  }

}
