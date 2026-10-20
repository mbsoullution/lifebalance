<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Aggregates account metadata for the admin list.
 *
 * Queries users_field_data, the wheel_created row in users_data, and the
 * snapshot base table. It never reads scores, answers, notes, or draft
 * values. The wheel mark is counted by presence, not by its timestamp.
 */
final class AdminUserStats {

  public const LIMIT = 50;

  /**
   * Seconds in the "ending within 7 days" tab.
   */
  public const ENDING_WITHIN = 604800;

  /**
   * Tabs of the account list.
   *
   * @var list<string>
   */
  public const TABS = [
    'all',
    'premium',
    'free',
    'trial',
    'paid',
    'manual',
    'nowheel',
    'wheel',
    'ending',
    'expired',
  ];

  /**
   * Sort keys mapped to fixed SQL expressions.
   *
   * @var list<string>
   */
  public const SORTS = [
    'uid',
    'name',
    'created',
    'login',
    'snapshots',
    'last_snapshot',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TranslationInterface $translation,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AccountProxyInterface $currentUser,
    private readonly Clock $clock,
  ) {}

  /**
   * One page of accounts.
   *
   * @return list<array<string, int|string|null>>
   *   Rows for the requested page.
   */
  public function page(string $tab, string $search, string $sort, string $direction, bool $emails, int $page, string $registeredFrom = '', string $registeredTo = ''): array {
    $query = $this->baseQuery($tab, $search, $emails, $registeredFrom, $registeredTo);
    $this->addAggregates($query);
    $query->orderBy($this->orderColumn($sort), $this->direction($direction));
    $query->orderBy('u.uid', 'DESC');
    $query->range(max(0, $page) * self::LIMIT, self::LIMIT);
    return $this->rows($query, $emails);
  }

  /**
   * Every account matching the filter, for the CSV export.
   *
   * @return list<array<string, int|string|null>>
   *   All matching rows.
   */
  public function all(string $tab, string $search, string $sort, string $direction, bool $emails, string $registeredFrom = '', string $registeredTo = ''): array {
    $query = $this->baseQuery($tab, $search, $emails, $registeredFrom, $registeredTo);
    $this->addAggregates($query);
    $query->orderBy($this->orderColumn($sort), $this->direction($direction));
    $query->orderBy('u.uid', 'DESC');
    return $this->rows($query, $emails);
  }

  /**
   * One account, or NULL when the uid is missing.
   *
   * @return array<string, int|string|null>|null
   *   The aggregate row.
   */
  public function one(int $uid, bool $emails): ?array {
    if ($uid < 1) {
      return NULL;
    }
    $query = $this->baseQuery('all', '', $emails);
    $query->condition('u.uid', $uid);
    $this->addAggregates($query);
    $query->range(0, 1);
    $rows = $this->rows($query, $emails);
    return $rows[0] ?? NULL;
  }

  /**
   * How many accounts match the filter.
   */
  public function count(string $tab, string $search, bool $emails, string $registeredFrom = '', string $registeredTo = ''): int {
    $statement = $this->baseQuery($tab, $search, $emails, $registeredFrom, $registeredTo)->countQuery()->execute();
    $value = $statement ? $statement->fetchField() : FALSE;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Stage 0 totals. Snapshots in the period are rows, not people.
   *
   * @return array{
   *   accounts: int,
   *   started: int,
   *   with_snapshot: int,
   *   snapshots: int,
   *   premium: int,
   *   premium_admin: int,
   *   premium_trial: int,
   *   premium_payment: int,
   *   ending: int,
   *   expired: int,
   *   with_goals: int,
   *   goals_done: int
   *   }
   *   Counts only. Goal counts are totals, never titles or steps.
   */
  public function statistics(int $since): array {
    $accounts = $this->database->select('users_field_data', 'u');
    $accounts->condition('u.default_langcode', 1);
    $accounts->condition('u.uid', 0, '>');
    $account_count = $accounts->countQuery()->execute()?->fetchField();

    $with_snapshot = $this->database->select('lifebalance_snapshot', 's');
    $with_snapshot->addExpression('COUNT(DISTINCT s.uid)');
    $with_snapshot_count = $with_snapshot->execute()?->fetchField();

    $snapshots = $this->database->select('lifebalance_snapshot', 's');
    $snapshots->addExpression('COUNT(s.id)');
    if ($since > 0) {
      $snapshots->condition('s.created', $since, '>=');
    }
    $snapshot_count = $snapshots->execute()?->fetchField();

    $started = $this->database->select('users_data', 'd');
    $started->condition('d.module', WheelCreation::MODULE);
    $started->condition('d.name', WheelCreation::NAME);
    $started->condition('d.uid', 0, '>');
    $started->addExpression('COUNT(DISTINCT d.uid)');
    $started_count = $started->execute()?->fetchField();

    $now = $this->clock->now();
    return [
      'accounts' => is_numeric($account_count) ? (int) $account_count : 0,
      'started' => is_numeric($started_count) ? (int) $started_count : 0,
      'with_snapshot' => is_numeric($with_snapshot_count) ? (int) $with_snapshot_count : 0,
      'snapshots' => is_numeric($snapshot_count) ? (int) $snapshot_count : 0,
      'premium' => $this->countActive($now, NULL),
      'premium_admin' => $this->countActive($now, Entitlement::SOURCE_ADMIN),
      'premium_trial' => $this->countActive($now, Entitlement::SOURCE_TRIAL),
      'premium_payment' => $this->countActive($now, Entitlement::SOURCE_PAYMENT),
      'ending' => $this->countEnding($now),
      'expired' => $this->countExpired($now),
    ] + $this->goalCounts();
  }

  /**
   * People with a goal, and goals marked done. Texts are not read.
   *
   * @return array{with_goals: int, goals_done: int}
   *   Zero when the goal table is not installed.
   */
  private function goalCounts(): array {
    if (!$this->database->schema()->tableExists('lifebalance_goal')) {
      return ['with_goals' => 0, 'goals_done' => 0];
    }
    $people = $this->database->select('lifebalance_goal', 'g');
    $people->addExpression('COUNT(DISTINCT g.uid)');
    $people_count = $people->execute()?->fetchField();
    $done = $this->database->select('lifebalance_goal', 'g');
    $done->condition('g.status', 'done');
    $done->addExpression('COUNT(g.id)');
    $done_count = $done->execute()?->fetchField();
    return [
      'with_goals' => is_numeric($people_count) ? (int) $people_count : 0,
      'goals_done' => is_numeric($done_count) ? (int) $done_count : 0,
    ];
  }

  /**
   * CSV for the filtered accounts. Email is included only when allowed.
   *
   * @param list<array<string, int|string|null>> $records
   *   Rows from all().
   * @param bool $emails
   *   Whether the mail column is included.
   */
  public function csv(array $records, bool $emails): string {
    $handle = fopen('php://temp', 'r+');
    if ($handle === FALSE) {
      throw new \RuntimeException('The export could not be opened.');
    }
    $header = [
      'uid',
      'name',
      'created',
      'login',
      'state',
      'snapshots',
      'last_snapshot',
      'plan',
      'source',
      'starts',
      'ends',
    ];
    if ($emails) {
      $header[] = 'mail';
    }
    fputcsv($handle, $header);
    foreach ($records as $record) {
      $last = $record['last_snapshot'];
      $cells = [
        (string) $record['uid'],
        $this->cell((string) $record['name']),
        $this->formatTimestamp((int) $record['created'], FALSE),
        $this->formatTimestamp((int) $record['login'], FALSE),
        $this->cell($this->stateLabel((string) $record['state'])),
        (string) $record['snapshots'],
        is_int($last) ? $this->formatTimestamp($last, FALSE) : '',
        $this->cell($this->planLabel((string) $record['plan'])),
        $this->cell($this->sourceLabels((string) $record['source'])),
        $this->csvStart($record['access_starts']),
        $this->csvEnd($record),
      ];
      if ($emails) {
        $cells[] = $this->cell((string) ($record['mail'] ?? ''));
      }
      fputcsv($handle, $cells);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    if ($csv === FALSE) {
      throw new \RuntimeException('The export could not be read.');
    }
    return $csv;
  }

  /**
   * Clock time in the current person's timezone. Empty when the stamp is 0.
   */
  public function formatTimestamp(int $timestamp, bool $dash): string {
    if ($timestamp < 1) {
      return $dash ? '—' : '';
    }
    $zone = $this->currentUser->getTimeZone();
    return $this->dateFormatter->format($timestamp, 'custom', 'd.m.Y H:i', $zone !== '' ? $zone : NULL);
  }

  /**
   * Ukrainian label for a plan key.
   */
  public function planLabel(string $plan): string {
    $label = match ($plan) {
      'premium' => $this->translation->translate('Premium'),
      'expired' => $this->translation->translate('Завершився'),
      default => $this->translation->translate('Free'),
    };
    return (string) $label;
  }

  /**
   * Ukrainian label for a source key. Empty when there is no record.
   */
  public function sourceLabel(string $source): string {
    $label = match ($source) {
      Entitlement::SOURCE_PAYMENT => $this->translation->translate('Оплата'),
      Entitlement::SOURCE_ADMIN => $this->translation->translate('Надання'),
      Entitlement::SOURCE_TRIAL => $this->translation->translate('Ознайомлення'),
      Entitlement::SOURCE_PROMO => $this->translation->translate('Промо'),
      default => '',
    };
    return (string) $label;
  }

  /**
   * Ukrainian labels for one or more comma-separated sources.
   */
  public function sourceLabels(string $sources): string {
    $labels = [];
    foreach (explode(',', $sources) as $source) {
      $label = $this->sourceLabel(trim($source));
      if ($label !== '') {
        $labels[] = $label;
      }
    }
    return implode(', ', $labels);
  }

  /**
   * Start or end of one Europe/Kyiv calendar day, or 0 when it is not a date.
   */
  public function kyivDay(string $value, bool $end): int {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
      return 0;
    }
    $zone = new \DateTimeZone('Europe/Kyiv');
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
      return 0;
    }
    if ($end) {
      $date = $date->setTime(23, 59, 59);
    }
    return $date->getTimestamp();
  }

  /**
   * Ukrainian label for a stored state key.
   */
  public function stateLabel(string $state): string {
    $label = match ($state) {
      'snapshot' => $this->translation->translate('Є зріз'),
      'started' => $this->translation->translate('Колесо створено'),
      default => $this->translation->translate('Зареєстрований'),
    };
    return (string) $label;
  }

  /**
   * Known tab, or the full list.
   */
  public function tab(string $tab): string {
    return in_array($tab, self::TABS, TRUE) ? $tab : 'all';
  }

  /**
   * Known sort key, or registration date.
   */
  public function sort(string $sort): string {
    return in_array($sort, self::SORTS, TRUE) ? $sort : 'created';
  }

  /**
   * Ascending or descending.
   */
  public function direction(string $direction): string {
    return strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
  }

  /**
   * Accounts query without snapshot field tables.
   */
  private function baseQuery(string $tab, string $search, bool $emails, string $registeredFrom = '', string $registeredTo = ''): SelectInterface {
    $query = $this->database->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name', 'created', 'login']);
    if ($emails) {
      $query->addField('u', 'mail');
    }
    $query->condition('u.default_langcode', 1);
    $query->condition('u.uid', 0, '>');
    $search = trim($search);
    if ($search !== '') {
      $like = '%' . $this->database->escapeLike(mb_substr($search, 0, 128)) . '%';
      $or = $query->orConditionGroup()->condition('u.name', $like, 'LIKE');
      if ($emails) {
        $or->condition('u.mail', $like, 'LIKE');
      }
      if (ctype_digit($search) && strlen($search) < 10) {
        $or->condition('u.uid', (int) $search);
      }
      $query->condition($or);
    }
    $from = $this->kyivDay($registeredFrom, FALSE);
    $to = $this->kyivDay($registeredTo, TRUE);
    if ($from > 0) {
      $query->condition('u.created', $from, '>=');
    }
    if ($to > 0) {
      $query->condition('u.created', $to, '<=');
    }
    $now = $this->clock->now();
    switch ($this->tab($tab)) {
      case 'nowheel':
        $query->notExists($this->wheelExists());
        break;

      case 'wheel':
        $query->exists($this->wheelExists());
        break;

      case 'premium':
        $query->exists($this->activeExists($now, FALSE));
        break;

      case 'free':
        $query->notExists($this->entitlementExists());
        break;

      case 'trial':
        $query->exists($this->activeExists($now, FALSE, Entitlement::SOURCE_TRIAL));
        break;

      case 'paid':
        $query->exists($this->activeExists($now, FALSE, Entitlement::SOURCE_PAYMENT));
        break;

      case 'manual':
        $query->exists($this->activeExists($now, FALSE, Entitlement::SOURCE_ADMIN));
        break;

      case 'ending':
        $query->exists($this->activeExists($now, TRUE));
        break;

      case 'expired':
        $query->exists($this->entitlementExists());
        $query->notExists($this->activeExists($now, FALSE));
        break;
    }
    return $query;
  }

  /**
   * Count and latest creation time from the snapshot base table.
   */
  private function addAggregates(SelectInterface $query): void {
    $query->addExpression('(SELECT COUNT(s.id) FROM {lifebalance_snapshot} s WHERE s.uid = u.uid)', 'snapshots');
    $query->addExpression('(SELECT MAX(s.created) FROM {lifebalance_snapshot} s WHERE s.uid = u.uid)', 'last_snapshot');
    $query->addExpression('EXISTS(SELECT 1 FROM {users_data} d WHERE d.uid = u.uid AND d.module = :wheel_module AND d.name = :wheel_name)', 'has_wheel', [
      ':wheel_module' => WheelCreation::MODULE,
      ':wheel_name' => WheelCreation::NAME,
    ]);
  }

  /**
   * Wheel mark presence. The stored timestamp is not selected.
   */
  private function wheelExists(): SelectInterface {
    $wheel = $this->database->select('users_data', 'd');
    $wheel->addExpression('1');
    $wheel->where('[d].[uid] = [u].[uid]');
    $wheel->condition('d.module', WheelCreation::MODULE);
    $wheel->condition('d.name', WheelCreation::NAME);
    return $wheel;
  }

  /**
   * Fixed order expression for an allowlisted sort key.
   */
  private function orderColumn(string $sort): string {
    return match ($this->sort($sort)) {
      'uid' => 'u.uid',
      'name' => 'u.name',
      'login' => 'u.login',
      'snapshots' => 'snapshots',
      'last_snapshot' => 'last_snapshot',
      default => 'u.created',
    };
  }

  /**
   * Maps a statement to plain rows.
   *
   * @return list<array<string, int|string|null>>
   *   Aggregate rows.
   */
  private function rows(SelectInterface $query, bool $emails): array {
    $statement = $query->execute();
    if ($statement === NULL) {
      return [];
    }
    $rows = [];
    foreach ($statement as $record) {
      if (!is_object($record)) {
        continue;
      }
      $snapshots = $this->intProp($record, 'snapshots');
      $wheel = $this->intProp($record, 'has_wheel') === 1;
      $row = [
        'uid' => $this->intProp($record, 'uid'),
        'name' => $this->stringProp($record, 'name'),
        'created' => $this->intProp($record, 'created'),
        'login' => $this->intProp($record, 'login'),
        'state' => $this->state($wheel, $snapshots),
        'snapshots' => $snapshots,
        'last_snapshot' => $this->nullableInt($record, 'last_snapshot'),
      ];
      if ($emails) {
        $row['mail'] = $this->stringProp($record, 'mail');
      }
      $rows[] = $row;
    }
    $access = $this->accessByUid(array_map(static fn (array $row): int => (int) $row['uid'], $rows));
    foreach ($rows as $index => $row) {
      $extra = $access[(int) $row['uid']] ?? [
        'plan' => 'free',
        'source' => '',
        'access_starts' => NULL,
        'access_ends' => NULL,
      ];
      $rows[$index] = $row + $extra;
    }
    return $rows;
  }

  /**
   * Wheel state key: registered, created, or snapshot.
   */
  private function state(bool $wheel, int $snapshots): string {
    if ($snapshots > 0) {
      return 'snapshot';
    }
    if ($wheel) {
      return 'started';
    }
    return 'registered';
  }

  /**
   * Integer column, or 0 when the driver returns NULL.
   */
  private function intProp(object $record, string $name): int {
    $value = $record->{$name} ?? NULL;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Nullable integer column.
   */
  private function nullableInt(object $record, string $name): ?int {
    $value = $record->{$name} ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * String column.
   */
  private function stringProp(object $record, string $name): string {
    $value = $record->{$name} ?? '';
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Start cell for the CSV. Empty when the account has no record.
   */
  private function csvStart(int|string|null $timestamp): string {
    return is_int($timestamp) ? $this->formatTimestamp($timestamp, FALSE) : '';
  }

  /**
   * End cell for the CSV. Empty when the account has no record.
   *
   * @param array<string, int|string|null> $record
   *   An aggregate row.
   */
  private function csvEnd(array $record): string {
    if ((string) $record['plan'] === 'free') {
      return '';
    }
    $ends = $record['access_ends'];
    if ($ends === NULL) {
      return $this->cell((string) $this->translation->translate('Безстроково'));
    }
    return is_int($ends) ? $this->formatTimestamp($ends, FALSE) : '';
  }

  /**
   * Active Premium window for the list tabs.
   */
  private function activeExists(int $now, bool $ending, ?string $source = NULL): SelectInterface {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->addExpression('1');
    $query->where('[e].[uid] = [u].[uid]');
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $query->condition('e.status', Entitlement::STATUS_ACTIVE);
    $query->condition('e.starts', $now, '<=');
    if ($ending) {
      $query->where('[e].[ends] > :lb_ending_after AND [e].[ends] <= :lb_ending_until', [
        ':lb_ending_after' => $now,
        ':lb_ending_until' => $now + self::ENDING_WITHIN,
      ]);
      return $query;
    }
    $query->where('([e].[ends] IS NULL OR [e].[ends] > :lb_active_ends)', [
      ':lb_active_ends' => $now,
    ]);
    if ($source !== NULL) {
      $query->condition('e.source', $source);
    }
    return $query;
  }

  /**
   * Any Premium record, including revoked and already ended ones.
   */
  private function entitlementExists(): SelectInterface {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->addExpression('1');
    $query->where('[e].[uid] = [u].[uid]');
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    return $query;
  }

  /**
   * Plan and the record shown in the list. Reasons are not selected.
   *
   * @param list<int> $uids
   *   Accounts on this page.
   *
   * @return array<int, array<string, int|string|null>>
   *   Plan, source, start, and end, keyed by uid.
   */
  private function accessByUid(array $uids): array {
    $map = [];
    foreach ($uids as $uid) {
      $map[$uid] = [
        'plan' => 'free',
        'source' => '',
        'access_starts' => NULL,
        'access_ends' => NULL,
      ];
    }
    if ($uids === []) {
      return [];
    }
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->fields('e', ['id', 'uid', 'source', 'starts', 'ends', 'status']);
    $query->condition('e.uid', $uids, 'IN');
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $statement = $query->execute();
    if ($statement === NULL) {
      return $map;
    }
    $grouped = [];
    foreach ($statement as $record) {
      if (!is_object($record)) {
        continue;
      }
      $uid = $this->intProp($record, 'uid');
      $grouped[$uid][] = [
        'id' => $this->intProp($record, 'id'),
        'source' => $this->stringProp($record, 'source'),
        'starts' => $this->intProp($record, 'starts'),
        'ends' => $this->nullableInt($record, 'ends'),
        'status' => $this->stringProp($record, 'status'),
      ];
    }
    $now = $this->clock->now();
    foreach ($grouped as $uid => $records) {
      $map[$uid] = $this->pickAccess($records, $now);
    }
    return $map;
  }

  /**
   * Chooses the current record, or the latest ended one.
   *
   * @param list<array<string, int|string|null>> $records
   *   Records for one account.
   * @param int $now
   *   The instant used to decide which record is active.
   *
   * @return array<string, int|string|null>
   *   The list columns.
   */
  private function pickAccess(array $records, int $now): array {
    $active = [];
    foreach ($records as $record) {
      if ($this->rowActive($record, $now)) {
        $active[] = $record;
      }
    }
    $pool = $active !== [] ? $active : $records;
    usort($pool, function (array $left, array $right) use ($active): int {
      if ($active === []) {
        $by_end = ($right['ends'] ?? -1) <=> ($left['ends'] ?? -1);
        return $by_end !== 0 ? $by_end : $right['id'] <=> $left['id'];
      }
      $left_open = $left['ends'] === NULL ? 1 : 0;
      $right_open = $right['ends'] === NULL ? 1 : 0;
      if ($left_open !== $right_open) {
        return $right_open <=> $left_open;
      }
      $by_end = ($right['ends'] ?? 0) <=> ($left['ends'] ?? 0);
      return $by_end !== 0 ? $by_end : $right['id'] <=> $left['id'];
    });
    $chosen = $pool[0];
    $sources = [];
    foreach ($active as $record) {
      $source = (string) $record['source'];
      if ($source !== '' && !in_array($source, $sources, TRUE)) {
        $sources[] = $source;
      }
    }
    sort($sources);
    return [
      'plan' => $active === [] ? 'expired' : 'premium',
      'source' => $sources === [] ? (string) $chosen['source'] : implode(',', $sources),
      'access_starts' => $chosen['starts'],
      'access_ends' => $chosen['ends'],
    ];
  }

  /**
   * Whether a stored row grants access at this instant.
   *
   * @param array<string, int|string|null> $record
   *   One entitlement row.
   * @param int $now
   *   The instant used for the window.
   */
  private function rowActive(array $record, int $now): bool {
    if ($record['status'] !== Entitlement::STATUS_ACTIVE) {
      return FALSE;
    }
    if ($record['starts'] > $now) {
      return FALSE;
    }
    return $record['ends'] === NULL || $record['ends'] > $now;
  }

  /**
   * Distinct accounts with an active window, optionally one source.
   */
  private function countActive(int $now, ?string $source): int {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->addExpression('COUNT(DISTINCT [e].[uid])');
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $query->condition('e.status', Entitlement::STATUS_ACTIVE);
    $query->condition('e.starts', $now, '<=');
    $query->where('([e].[ends] IS NULL OR [e].[ends] > :lb_count_ends)', [
      ':lb_count_ends' => $now,
    ]);
    if ($source !== NULL) {
      $query->condition('e.source', $source);
    }
    return $this->countOf($query);
  }

  /**
   * Distinct accounts whose active end falls within seven days.
   */
  private function countEnding(int $now): int {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->addExpression('COUNT(DISTINCT [e].[uid])');
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $query->condition('e.status', Entitlement::STATUS_ACTIVE);
    $query->where('[e].[ends] > :lb_count_after AND [e].[ends] <= :lb_count_until', [
      ':lb_count_after' => $now,
      ':lb_count_until' => $now + self::ENDING_WITHIN,
    ]);
    return $this->countOf($query);
  }

  /**
   * Distinct accounts that had a record and have none active now.
   */
  private function countExpired(int $now): int {
    $query = $this->database->select('lifebalance_entitlement', 'outer_entitlement');
    $query->addExpression('COUNT(DISTINCT [outer_entitlement].[uid])');
    $query->condition('outer_entitlement.feature_set', Entitlement::FEATURE_PREMIUM);
    $active = $this->database->select('lifebalance_entitlement', 'e');
    $active->addExpression('1');
    $active->where('[e].[uid] = [outer_entitlement].[uid]');
    $active->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $active->condition('e.status', Entitlement::STATUS_ACTIVE);
    $active->condition('e.starts', $now, '<=');
    $active->where('([e].[ends] IS NULL OR [e].[ends] > :lb_expired_ends)', [
      ':lb_expired_ends' => $now,
    ]);
    $query->notExists($active);
    return $this->countOf($query);
  }

  /**
   * Integer result of a count expression.
   */
  private function countOf(SelectInterface $query): int {
    $value = $query->execute()?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Prefixes a cell that a spreadsheet would treat as a formula.
   */
  private function cell(string $value): string {
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
      return "'" . $value;
    }
    return $value;
  }

}
