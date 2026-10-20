<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\lifebalance\Form\EntitlementLogFilterForm;
use Drupal\lifebalance\Service\AdminUserStats;
use Drupal\lifebalance\Service\EntitlementAdmin;
use Drupal\lifebalance\Service\EntitlementLog;
use Symfony\Component\HttpFoundation\Request;

/**
 * Append-only entitlement log. Scores and notes stay out.
 */
final class EntitlementLogController extends ControllerBase {

  public const LIMIT = 50;

  public function __construct(
    protected EntitlementLog $log,
    protected EntitlementAdmin $admin,
    protected AdminUserStats $stats,
    protected PagerManagerInterface $pagerManager,
  ) {}

  /**
   * One page of the audit.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(Request $request): array {
    $filters = $this->filters($request);
    $total = $this->log->countFiltered(
      $filters['actor'],
      $filters['target'],
      $filters['action'],
      $filters['from'],
      $filters['to'],
    );
    $this->pagerManager->createPager($total, self::LIMIT);
    $offset = $this->pagerManager->findPage() * self::LIMIT;
    $rows = $this->log->slice(
      $filters['actor'],
      $filters['target'],
      $filters['action'],
      $filters['from'],
      $filters['to'],
      self::LIMIT,
      $offset,
    );
    $names = $this->names($rows);
    $build = [
      '#cache' => [
        'contexts' => ['user', 'user.permissions', 'timezone', 'url.query_args'],
        'max-age' => 0,
      ],
      'filter' => $this->formBuilder()->getForm(EntitlementLogFilterForm::class),
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Коли'),
          $this->t('Хто'),
          $this->t('Кому'),
          $this->t('Що'),
          $this->t('Чому'),
          $this->t('Було'),
          $this->t('Стало'),
        ],
        '#rows' => [],
        '#empty' => $this->t('Записів журналу немає.'),
      ],
      'pager' => ['#type' => 'pager'],
    ];
    foreach ($rows as $row) {
      $build['table']['#rows'][] = [
        $this->stats->formatTimestamp($row['created'], TRUE),
        $this->person($row['actor_uid'], $names, TRUE),
        $this->person($row['target_uid'], $names, FALSE),
        $this->admin->actionLabel($row['action']),
        $row['reason'],
        $this->term($row['old_ends'], FALSE),
        $this->term($row['new_ends'], TRUE),
      ];
    }
    return $build;
  }

  /**
   * Allowlisted query values. Unknown actions and dates are ignored.
   *
   * @return array{actor: int, target: int, action: string, from: int, to: int}
   *   Filter values.
   */
  private function filters(Request $request): array {
    $action = $request->query->get('action');
    $action = is_string($action) && $this->log->allows($action) ? $action : '';
    $from = $request->query->get('from');
    $to = $request->query->get('to');
    return [
      'actor' => $this->uid($request->query->get('actor')),
      'target' => $this->uid($request->query->get('target')),
      'action' => $action,
      'from' => $this->stats->kyivDay(is_string($from) ? $from : '', FALSE),
      'to' => $this->stats->kyivDay(is_string($to) ? $to : '', TRUE),
    ];
  }

  /**
   * Positive uid, or 0 when the query value is not one.
   */
  private function uid(mixed $value): int {
    if (!is_string($value) || !ctype_digit($value) || strlen($value) >= 10) {
      return 0;
    }
    return (int) $value;
  }

  /**
   * Account names for the uids on this page.
   *
   * @param list<array{actor_uid: int, target_uid: int}> $rows
   *   Audit rows.
   *
   * @return array<int, string>
   *   Names keyed by uid.
   */
  private function names(array $rows): array {
    $uids = [];
    foreach ($rows as $row) {
      if ($row['actor_uid'] > 0) {
        $uids[$row['actor_uid']] = $row['actor_uid'];
      }
      if ($row['target_uid'] > 0) {
        $uids[$row['target_uid']] = $row['target_uid'];
      }
    }
    if ($uids === []) {
      return [];
    }
    $names = [];
    $storage = $this->entityTypeManager()->getStorage('user');
    foreach ($storage->loadMultiple(array_values($uids)) as $account) {
      if ((int) $account->id() > 0) {
        $names[(int) $account->id()] = $account->getAccountName();
      }
    }
    return $names;
  }

  /**
   * Name for an audit uid.
   *
   * @param int $uid
   *   Actor or target uid. Zero means the account is gone.
   * @param array<int, string> $names
   *   Names keyed by uid.
   * @param bool $actor
   *   TRUE when the uid is the person who made the change.
   */
  private function person(int $uid, array $names, bool $actor): string {
    if ($actor && $uid < 1) {
      return (string) $this->t('Система');
    }
    if ($uid > 0 && isset($names[$uid])) {
      return $names[$uid];
    }
    return (string) $this->t('Обліковий запис видалено');
  }

  /**
   * End text. A missing previous end is a dash. A missing new end is open.
   */
  private function term(?int $ends, bool $next): string {
    if ($ends === NULL) {
      return $next ? (string) $this->t('Безстроково') : '—';
    }
    return $this->stats->formatTimestamp($ends, TRUE);
  }

}
