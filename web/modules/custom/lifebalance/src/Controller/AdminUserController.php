<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Form\AdminUserExportForm;
use Drupal\lifebalance\Form\AdminUserFilterForm;
use Drupal\lifebalance\Service\AdminUserStats;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EffectiveStatus;
use Drupal\lifebalance\Service\EntitlementAdmin;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\FunnelStats;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin list, card, and stage 0 statistics.
 *
 * The list and the card stay aggregate. Wheels open on a separate route
 * that only the site owner can reach.
 */
final class AdminUserController extends ControllerBase {

  public function __construct(
    protected AdminUserStats $stats,
    protected PagerManagerInterface $pagerManager,
    protected Clock $clock,
    protected EntitlementAdmin $entitlementAdmin,
    protected EntitlementCheckerInterface $checker,
    protected FunnelStats $funnel,
  ) {}

  /**
   * Account list with plan tabs. Scores and notes stay out.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(Request $request): array {
    $filters = $this->filters($request);
    $emails = $this->currentUser()->hasPermission('view lifebalance user emails');
    $total = $this->stats->count(
      $filters['tab'],
      $filters['search'],
      $emails,
      $filters['registered_from'],
      $filters['registered_to'],
    );
    $this->pagerManager->createPager($total, AdminUserStats::LIMIT);
    $page = $this->pagerManager->findPage();
    $build = [
      '#cache' => $this->personalCache(),
      'stats' => [
        '#type' => 'link',
        '#title' => $this->t('Статистика'),
        '#url' => Url::fromRoute('lifebalance.admin_stats'),
      ],
      'tabs' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->tabLink((string) $this->t('Усі'), 'all', $filters),
          $this->tabLink((string) $this->t('Premium'), 'premium', $filters),
          $this->tabLink((string) $this->t('Free'), 'free', $filters),
          $this->tabLink((string) $this->t('Trial'), 'trial', $filters),
          $this->tabLink((string) $this->t('Paid'), 'paid', $filters),
          $this->tabLink((string) $this->t('Manual'), 'manual', $filters),
          $this->tabLink((string) $this->t('Без колеса'), 'nowheel', $filters),
          $this->tabLink((string) $this->t('З колесом'), 'wheel', $filters),
          $this->tabLink((string) $this->t('Закінчується за 7 днів'), 'ending', $filters),
          $this->tabLink((string) $this->t('Завершився'), 'expired', $filters),
        ],
        '#attributes' => ['class' => ['lifebalance-admin-tabs']],
      ],
      'filter' => $this->formBuilder()->getForm(AdminUserFilterForm::class),
      'export' => $this->formBuilder()->getForm(AdminUserExportForm::class),
    ];
    if ($this->currentUser()->hasPermission('view lifebalance entitlement log')) {
      $build['log'] = [
        '#type' => 'link',
        '#title' => $this->t('Журнал доступу'),
        '#url' => Url::fromRoute('lifebalance.admin_entitlement_log'),
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $this->header($filters, $emails),
      '#rows' => [],
      '#empty' => $this->t('Нікого не знайдено.'),
    ];
    $build['pager'] = ['#type' => 'pager'];
    $records = $this->stats->page(
      $filters['tab'],
      $filters['search'],
      $filters['sort'],
      $filters['dir'],
      $emails,
      $page,
      $filters['registered_from'],
      $filters['registered_to'],
    );
    foreach ($records as $record) {
      $build['table']['#rows'][] = $this->tableRow($record, $emails);
    }
    $this->wheelColumn($build, $records);
    $this->moduleHandler()->alter('lifebalance_admin_user_list', $build, $records);
    return $build;
  }

  /**
   * One account card. Scores stay on the owner's wheel page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function card(UserInterface $user): array {
    if ((int) $user->id() < 1) {
      throw new NotFoundHttpException();
    }
    $emails = $this->currentUser()->hasPermission('view lifebalance user emails');
    $record = $this->stats->one((int) $user->id(), $emails);
    if ($record === NULL) {
      throw new NotFoundHttpException();
    }
    $rows = [
      [$this->t('Назва'), $record['name']],
      [$this->t('Стан'), $this->stats->stateLabel((string) $record['state'])],
      [$this->t('Реєстрація'), $this->stats->formatTimestamp((int) $record['created'], TRUE)],
      [$this->t('Останній вхід'), $this->stats->formatTimestamp((int) $record['login'], TRUE)],
      [$this->t('Зрізів'), (string) $record['snapshots']],
      [$this->t('Останній зріз'), $this->lastSnapshot($record['last_snapshot'])],
      [$this->t('План'), $this->stats->planLabel((string) $record['plan'])],
      [$this->t('Джерело'), $this->sourceOrDash((string) $record['source'])],
      [$this->t('Початок доступу'), $this->accessStart($record['access_starts'])],
      [$this->t('Завершення доступу'), $this->accessEnd($record)],
    ];
    if ($emails) {
      $rows[] = [$this->t('Електронна адреса'), (string) ($record['mail'] ?? '')];
    }
    $cache = $this->personalCache();
    $cache['tags'] = ['lifebalance_entitlement:' . $user->id()];
    $build = [
      '#cache' => $cache,
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('До списку'),
        '#url' => Url::fromRoute('lifebalance.admin_users'),
      ],
    ];
    $wheel = $this->wheelLink((int) $user->id());
    if ($wheel !== []) {
      $build['wheel'] = $wheel;
    }
    $build['table'] = [
      '#type' => 'table',
      '#rows' => $rows,
    ];
    $build['access'] = $this->accessBlock($user);
    $account = $user;
    $this->moduleHandler()->alter('lifebalance_user_card', $build, $account);
    return $build;
  }

  /**
   * Title for the card. Empty when the account is not a real user.
   */
  public function title(UserInterface $user): string {
    return (int) $user->id() > 0 ? $user->getDisplayName() : '';
  }

  /**
   * Counts for the statistics page. Other modules may add their own lines.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function statistics(Request $request): array {
    $period = $request->query->get('period');
    $days = is_string($period) && in_array($period, ['30', '90', '365', 'all'], TRUE) ? $period : '30';
    $since = $days === 'all' ? 0 : $this->clock->now() - ((int) $days * 86400);
    $metadata = $this->currentUser()->hasPermission('view lifebalance user metadata');
    $build = [
      '#cache' => $this->personalCache(),
      'periods' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->periodLink((string) $this->t('30 днів'), '30'),
          $this->periodLink((string) $this->t('90 днів'), '90'),
          $this->periodLink((string) $this->t('365 днів'), '365'),
          $this->periodLink((string) $this->t('Увесь час'), 'all'),
        ],
      ],
    ];
    if ($metadata) {
      $totals = $this->stats->statistics($since);
      $build['list'] = [
        '#type' => 'link',
        '#title' => $this->t('До списку'),
        '#url' => Url::fromRoute('lifebalance.admin_users'),
      ];
      $build['accounts'] = $this->line($this->t('Акаунти: @count', ['@count' => $totals['accounts']]));
      $build['started'] = $this->line($this->t('Колесо створено: @count (@percent)', [
        '@count' => $totals['started'],
        '@percent' => $this->percent($totals['started'], $totals['accounts']),
      ]));
      $build['with_snapshot'] = $this->line($this->t('Є зріз: @count (@percent)', [
        '@count' => $totals['with_snapshot'],
        '@percent' => $this->percent($totals['with_snapshot'], $totals['started']),
      ]));
      $build['snapshots'] = $this->line($this->t('Зрізів за період: @count', [
        '@count' => $totals['snapshots'],
      ]));
      $build['premium'] = $this->line($this->t('Активний Premium: @count', [
        '@count' => $totals['premium'],
      ]));
      $build['premium_admin'] = $this->line($this->t('Надання: @count', [
        '@count' => $totals['premium_admin'],
      ]));
      $build['premium_trial'] = $this->line($this->t('Ознайомлення: @count', [
        '@count' => $totals['premium_trial'],
      ]));
      $build['premium_payment'] = $this->line($this->t('Оплата: @count', [
        '@count' => $totals['premium_payment'],
      ]));
      $build['ending'] = $this->line($this->t('Закінчується за 7 днів: @count', [
        '@count' => $totals['ending'],
      ]));
      $build['expired'] = $this->line($this->t('Завершився: @count', [
        '@count' => $totals['expired'],
      ]));
      $build['with_goals'] = $this->line($this->t('Користувачі з цілями: @count', [
        '@count' => $totals['with_goals'],
      ]));
      $build['goals_done'] = $this->line($this->t('Виконані цілі: @count', [
        '@count' => $totals['goals_done'],
      ]));
    }
    $funnel = $this->funnel->counts($since);
    $build['funnel_registered'] = $this->line($this->t('Зареєстровані: @count', [
      '@count' => $funnel['registered'],
    ]));
    $build['funnel_wheel'] = $this->line($this->t('Створили колесо: @count', [
      '@count' => $funnel['wheel'],
    ]));
    $build['funnel_first'] = $this->line($this->t('Перший зріз: @count', [
      '@count' => $funnel['first_snapshot'],
    ]));
    $build['funnel_trial'] = $this->line($this->t('Активували ознайомлення: @count', [
      '@count' => $funnel['trial'],
    ]));
    $build['funnel_returned'] = $this->line($this->t('Повернулися через 30 днів: @count', [
      '@count' => $funnel['returned'],
    ]));
    $this->moduleHandler()->alter('lifebalance_admin_stats', $build, $since);
    return $build;
  }

  /**
   * Allowlisted query values.
   *
   * @return array{
   *   tab: string,
   *   search: string,
   *   sort: string,
   *   dir: string,
   *   registered_from: string,
   *   registered_to: string
   *   }
   *   Filter values.
   */
  private function filters(Request $request): array {
    $tab = $request->query->get('tab');
    $search = $request->query->get('search');
    $sort = $request->query->get('sort');
    $dir = $request->query->get('dir');
    $from = $request->query->get('registered_from');
    $to = $request->query->get('registered_to');
    $direction = $this->stats->direction(is_string($dir) ? $dir : '');
    return [
      'tab' => $this->stats->tab(is_string($tab) ? $tab : ''),
      'search' => is_string($search) ? $search : '',
      'sort' => $this->stats->sort(is_string($sort) ? $sort : ''),
      'dir' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
      'registered_from' => is_string($from) ? $from : '',
      'registered_to' => is_string($to) ? $to : '',
    ];
  }

  /**
   * Table header with sort links.
   *
   * @param array<string, string> $filters
   *   Current tab, search, sort, direction, and registration dates.
   * @param bool $emails
   *   Whether the mail column is included.
   *
   * @return list<mixed>
   *   Header cells.
   */
  private function header(array $filters, bool $emails): array {
    $header = [
      $this->sortLink($this->t('UID'), 'uid', $filters),
      $this->sortLink($this->t("Ім'я"), 'name', $filters),
      $this->sortLink($this->t('Реєстрація'), 'created', $filters),
      $this->sortLink($this->t('Останній вхід'), 'login', $filters),
      $this->t('Стан'),
      $this->sortLink($this->t('Зрізів'), 'snapshots', $filters),
      $this->sortLink($this->t('Останній зріз'), 'last_snapshot', $filters),
      $this->t('План'),
      $this->t('Джерело'),
      $this->t('Початок'),
      $this->t('Завершення'),
    ];
    if ($emails) {
      $header[] = $this->t('Електронна адреса');
    }
    return $header;
  }

  /**
   * One table row. The name links to the card.
   *
   * @param array<string, int|string|null> $record
   *   An aggregate row.
   * @param bool $emails
   *   Whether the mail column is included.
   *
   * @return list<mixed>
   *   Cell values.
   */
  private function tableRow(array $record, bool $emails): array {
    $row = [
      (string) $record['uid'],
      [
        'data' => [
          '#type' => 'link',
          '#title' => (string) $record['name'],
          '#url' => Url::fromRoute('lifebalance.admin_user', ['user' => $record['uid']]),
        ],
      ],
      $this->stats->formatTimestamp((int) $record['created'], TRUE),
      $this->stats->formatTimestamp((int) $record['login'], TRUE),
      $this->stats->stateLabel((string) $record['state']),
      (string) $record['snapshots'],
      $this->lastSnapshot($record['last_snapshot']),
      $this->stats->planLabel((string) $record['plan']),
      $this->sourceOrDash((string) $record['source']),
      $this->accessStart($record['access_starts']),
      $this->accessEnd($record),
    ];
    if ($emails) {
      $row[] = (string) ($record['mail'] ?? '');
    }
    return $row;
  }

  /**
   * Sort link that keeps the current tab and search.
   *
   * @param \Stringable|string $label
   *   Visible link text.
   * @param string $sort
   *   Allowlisted sort key.
   * @param array<string, string> $filters
   *   Current tab, search, sort, direction, and registration dates.
   *
   * @return array<string, mixed>
   *   A link render element.
   */
  private function sortLink(
    \Stringable|string $label,
    string $sort,
    array $filters,
  ): array {
    $next = 'asc';
    if ($filters['sort'] === $sort && $filters['dir'] === 'asc') {
      $next = 'desc';
    }
    return [
      'data' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('lifebalance.admin_users', [], [
          'query' => [
            'tab' => $filters['tab'],
            'search' => $filters['search'],
            'sort' => $sort,
            'dir' => $next,
            'registered_from' => $filters['registered_from'],
            'registered_to' => $filters['registered_to'],
          ],
        ]),
      ],
    ];
  }

  /**
   * Tab link.
   *
   * @param string $label
   *   Visible link text.
   * @param string $tab
   *   Allowlisted tab.
   * @param array<string, string> $filters
   *   Current tab, search, sort, direction, and registration dates.
   *
   * @return array<string, mixed>
   *   A link render element.
   */
  private function tabLink(string $label, string $tab, array $filters): array {
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromRoute('lifebalance.admin_users', [], [
        'query' => [
          'tab' => $tab,
          'search' => $filters['search'],
          'sort' => $filters['sort'],
          'dir' => $filters['dir'],
          'registered_from' => $filters['registered_from'],
          'registered_to' => $filters['registered_to'],
        ],
      ]),
    ];
  }

  /**
   * Period link for the statistics page.
   *
   * @param string $label
   *   Visible link text.
   * @param string $period
   *   Allowlisted period.
   *
   * @return array<string, mixed>
   *   A link render element.
   */
  private function periodLink(string $label, string $period): array {
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromRoute('lifebalance.admin_stats', [], [
        'query' => ['period' => $period],
      ]),
    ];
  }

  /**
   * Latest snapshot time, or a dash.
   *
   * @param int|string|null $timestamp
   *   Unix time, when the account has a snapshot.
   */
  private function lastSnapshot(int|string|null $timestamp): string {
    return is_int($timestamp) ? $this->stats->formatTimestamp($timestamp, TRUE) : '—';
  }

  /**
   * One statistics paragraph.
   *
   * @param \Stringable|string $text
   *   Paragraph text.
   *
   * @return array<string, mixed>
   *   A paragraph render element.
   */
  private function line(\Stringable|string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
    ];
  }

  /**
   * Share of the part, shown with one decimal.
   *
   * @param int $part
   *   Numerator.
   * @param int $whole
   *   Denominator.
   */
  private function percent(int $part, int $whole): string {
    if ($whole < 1) {
      return '0.0%';
    }
    return number_format(($part / $whole) * 100, 1, '.', '') . '%';
  }

  /**
   * Access block: records, history, and the manual actions.
   *
   * @return array<string, mixed>
   *   A render array. Reasons require the history permission.
   */
  private function accessBlock(UserInterface $user): array {
    $manage = $this->currentUser()->hasPermission('manage lifebalance premium');
    $history = $this->currentUser()->hasPermission('view lifebalance entitlement history');
    $status = $this->checker->getEffectiveStatus($user);
    $build = [
      '#type' => 'container',
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->statusLine($status),
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Доступ'),
      ],
    ];
    if ($manage) {
      $build['grant'] = [
        '#type' => 'link',
        '#title' => $this->t('Надати'),
        '#url' => Url::fromRoute('lifebalance.admin_user_grant', [
          'user' => $user->id(),
        ]),
      ];
    }
    $records = $this->entitlementAdmin->records((int) $user->id());
    if ($records === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Записів доступу немає.'),
      ];
      return $build;
    }
    foreach ($records as $record) {
      $build['record_' . $record['id']] = $this->recordBlock($user, $record, $manage, $history);
    }
    return $build;
  }

  /**
   * One entitlement record and, when allowed, its revisions.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account on the card.
   * @param array<string, mixed> $record
   *   One record from EntitlementAdmin.
   * @param bool $manage
   *   Whether the manual forms are linked.
   * @param bool $history
   *   Whether revision reasons are shown.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function recordBlock(UserInterface $user, array $record, bool $manage, bool $history): array {
    $rows = [
      [$this->t('Джерело'), $this->sourceOrDash($record['source'])],
      [$this->t('Початок'), $this->stats->formatTimestamp($record['starts'], TRUE)],
      [$this->t('Завершення'), $this->recordEnd($record['ends'])],
      [$this->t('Стан'), $this->recordState($record)],
    ];
    $build = [
      '#type' => 'container',
      'table' => [
        '#type' => 'table',
        '#rows' => $rows,
      ],
    ];
    if ($manage && $record['manual'] && $record['status'] === Entitlement::STATUS_ACTIVE) {
      $build['actions'] = [
        '#theme' => 'item_list',
        '#items' => $this->operationLinks($user, $record),
      ];
    }
    if ($history) {
      $revision_rows = [];
      foreach ($record['revisions'] as $revision) {
        $revision_rows[] = [
          $this->stats->formatTimestamp($revision['created'], TRUE),
          $revision['actor'],
          $revision['action'],
          $revision['reason'],
        ];
      }
      $build['history'] = [
        '#type' => 'details',
        '#title' => $this->t('Історія'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Коли'),
            $this->t('Хто'),
            $this->t('Що'),
            $this->t('Причина'),
          ],
          '#rows' => $revision_rows,
        ],
      ];
    }
    return $build;
  }

  /**
   * Links for the manual operations that this record still allows.
   *
   * @param \Drupal\user\UserInterface $user
   *   The account on the card.
   * @param array<string, mixed> $record
   *   The record being shown.
   *
   * @return list<array<string, mixed>>
   *   Link elements.
   */
  private function operationLinks(UserInterface $user, array $record): array {
    $parameters = [
      'user' => $user->id(),
      'entitlement' => $record['id'],
    ];
    $links = [];
    if ($record['ends'] !== NULL) {
      $links[] = [
        '#type' => 'link',
        '#title' => $this->t('Продовжити'),
        '#url' => Url::fromRoute('lifebalance.admin_entitlement_extend', $parameters),
      ];
    }
    if ($record['ends'] === NULL || $record['open']) {
      $links[] = [
        '#type' => 'link',
        '#title' => $this->t('Скоротити'),
        '#url' => Url::fromRoute('lifebalance.admin_entitlement_shorten', $parameters),
      ];
    }
    $links[] = [
      '#type' => 'link',
      '#title' => $this->t('Скасувати'),
      '#url' => Url::fromRoute('lifebalance.admin_entitlement_revoke', $parameters),
    ];
    return $links;
  }

  /**
   * End text for one record. NULL means no end.
   */
  private function recordEnd(?int $ends): string {
    if ($ends === NULL) {
      return (string) $this->t('Безстроково');
    }
    return $this->stats->formatTimestamp($ends, TRUE);
  }

  /**
   * Visible state. Expired is calculated, not stored.
   *
   * @param array{status: string, open: bool, starts: int} $record
   *   The record being shown.
   */
  private function recordState(array $record): string {
    if ($record['status'] === Entitlement::STATUS_REVOKED) {
      return (string) $this->t('Скасовано');
    }
    if ($record['open']) {
      return (string) $this->t('Діє');
    }
    if ($record['starts'] > $this->clock->now()) {
      return (string) $this->t('Очікує');
    }
    return (string) $this->t('Завершився');
  }

  /**
   * Source label, or a dash when the account has no record.
   */
  private function sourceOrDash(string $source): string {
    $label = $this->stats->sourceLabels($source);
    return $label === '' ? '—' : $label;
  }

  /**
   * Effective status and the sources that are active now.
   */
  private function statusLine(EffectiveStatus $status): string {
    $label = match ($status->status) {
      EffectiveStatus::TRIAL => (string) $this->t('Ознайомлення'),
      EffectiveStatus::PREMIUM => (string) $this->t('Premium'),
      EffectiveStatus::EXPIRED => (string) $this->t('Завершився'),
      default => (string) $this->t('Free'),
    };
    $names = [];
    foreach ($status->sources as $source) {
      $text = $this->stats->sourceLabel($source);
      if ($text !== '') {
        $names[] = $text;
      }
    }
    if ($names === []) {
      return (string) $this->t('Статус: @status', ['@status' => $label]);
    }
    return (string) $this->t('Статус: @status. Джерела: @sources', [
      '@status' => $label,
      '@sources' => implode(', ', $names),
    ]);
  }

  /**
   * Start of the displayed record, or a dash.
   */
  private function accessStart(int|string|null $timestamp): string {
    return is_int($timestamp) ? $this->stats->formatTimestamp($timestamp, TRUE) : '—';
  }

  /**
   * End of the displayed record. Free accounts get a dash.
   *
   * @param array<string, int|string|null> $record
   *   An aggregate row.
   */
  private function accessEnd(array $record): string {
    if ((string) ($record['plan'] ?? '') === 'free') {
      return '—';
    }
    $ends = $record['access_ends'] ?? NULL;
    if ($ends === NULL) {
      return (string) $this->t('Безстроково');
    }
    return is_int($ends) ? $this->stats->formatTimestamp($ends, TRUE) : '—';
  }

  /**
   * Adds a wheel link when the viewer is the site owner.
   *
   * @param array<string, mixed> $build
   *   The list render array.
   * @param list<array<string, int|string|null>> $records
   *   Rows already placed in the table.
   */
  private function wheelColumn(array &$build, array $records): void {
    $allowed = $this->currentUser()->hasPermission('administer lifebalance snapshots');
    if (!$allowed) {
      return;
    }
    $build['table']['#header'][] = (string) $this->t('Колесо');
    foreach ($records as $index => $record) {
      $build['table']['#rows'][$index][] = [
        'data' => $this->wheelLink((int) $record['uid']),
      ];
    }
  }

  /**
   * Link to one account's wheels, or an empty array without permission.
   *
   * @return array<string, mixed>
   *   A link render element.
   */
  private function wheelLink(int $uid): array {
    $allowed = $this->currentUser()->hasPermission('administer lifebalance snapshots');
    if ($uid < 1 || !$allowed) {
      return [];
    }
    return [
      '#type' => 'link',
      '#title' => $this->t('Відкрити'),
      '#url' => Url::fromRoute('lifebalance.admin_user_wheel', [
        'user' => $uid,
      ]),
    ];
  }

  /**
   * Personal cache metadata. The list changes with users and snapshots.
   *
   * @return array<string, mixed>
   *   Cache settings.
   */
  private function personalCache(): array {
    return [
      'contexts' => ['user', 'user.permissions', 'timezone', 'url.query_args'],
      'max-age' => 0,
    ];
  }

}
