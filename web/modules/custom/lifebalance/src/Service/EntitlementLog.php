<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Append-only audit of entitlement changes.
 *
 * Rows are inserted. The only update sets target_uid to NULL when the
 * account is deleted. There is no delete. Listing reads the same rows.
 */
final class EntitlementLog {

  public const GRANT = 'grant';

  public const EXTEND = 'extend';

  public const SHORTEN = 'shorten';

  public const REVOKE = 'revoke';

  public const TRIAL_START = 'trial_start';

  public const PAID_ACTIVATE = 'paid_activate';

  public const REFUND_REVOKE = 'refund_revoke';

  public const EXPIRE_NOTICE = 'expire_notice';

  public const TRIAL_REMIND = 'trial_remind';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Inserts one audit row.
   */
  public function append(
    int $entitlementId,
    int $targetUid,
    ?int $actorUid,
    string $action,
    ?int $oldEnds,
    ?int $newEnds,
    ?string $oldStatus,
    string $newStatus,
    string $reason,
    int $created,
  ): void {
    $this->database->insert('lifebalance_entitlement_log')
      ->fields([
        'entitlement_id' => $entitlementId,
        'target_uid' => $targetUid > 0 ? $targetUid : NULL,
        'actor_uid' => $actorUid !== NULL && $actorUid > 0 ? $actorUid : NULL,
        'action' => $action,
        'old_ends' => $oldEnds,
        'new_ends' => $newEnds,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'reason' => $reason,
        'created' => $created,
      ])
      ->execute();
  }

  /**
   * Rows for one record, oldest first.
   *
   * @return list<array{
   *   created: int,
   *   actor_uid: int,
   *   action: string,
   *   reason: string,
   *   old_ends: int|null,
   *   new_ends: int|null,
   *   old_status: string,
   *   new_status: string
   *   }>
   *   Audit rows. The timestamp value is not a score.
   */
  public function forEntitlement(int $entitlementId): array {
    $statement = $this->database->select('lifebalance_entitlement_log', 'l')
      ->fields('l', [
        'created',
        'actor_uid',
        'action',
        'reason',
        'old_ends',
        'new_ends',
        'old_status',
        'new_status',
      ])
      ->condition('entitlement_id', $entitlementId)
      ->orderBy('id', 'ASC')
      ->execute();
    if ($statement === NULL) {
      return [];
    }
    $rows = [];
    foreach ($statement as $record) {
      if (!is_object($record)) {
        continue;
      }
      $rows[] = [
        'created' => is_numeric($record->created) ? (int) $record->created : 0,
        'actor_uid' => is_numeric($record->actor_uid) ? (int) $record->actor_uid : 0,
        'action' => (string) $record->action,
        'reason' => (string) $record->reason,
        'old_ends' => is_numeric($record->old_ends) ? (int) $record->old_ends : NULL,
        'new_ends' => is_numeric($record->new_ends) ? (int) $record->new_ends : NULL,
        'old_status' => (string) $record->old_status,
        'new_status' => (string) $record->new_status,
      ];
    }
    return $rows;
  }

  /**
   * Keeps the audit and forgets which account it described.
   */
  public function anonymizeTarget(int $uid): void {
    $table = 'lifebalance_entitlement_log';
    if ($uid < 1 || !$this->database->schema()->tableExists($table)) {
      return;
    }
    $this->database->update('lifebalance_entitlement_log')
      ->fields(['target_uid' => NULL])
      ->condition('target_uid', $uid)
      ->execute();
  }

  /**
   * Stored actions the log page can filter on.
   *
   * @return list<string>
   *   Action keys.
   */
  public function actions(): array {
    return [
      self::GRANT,
      self::EXTEND,
      self::SHORTEN,
      self::REVOKE,
      self::TRIAL_START,
      self::PAID_ACTIVATE,
      self::REFUND_REVOKE,
      self::EXPIRE_NOTICE,
      self::TRIAL_REMIND,
    ];
  }

  /**
   * Whether the action is one this log stores.
   */
  public function allows(string $action): bool {
    return in_array($action, $this->actions(), TRUE);
  }

  /**
   * How many audit rows match the filters.
   */
  public function countFiltered(int $actor, int $target, string $action, int $from, int $to): int {
    $value = $this->filtered($actor, $target, $action, $from, $to)
      ->countQuery()
      ->execute()
      ?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * One page of audit rows, newest first.
   *
   * The query does not read scores, answers, or notes.
   *
   * @return list<array{
   *   created: int,
   *   actor_uid: int,
   *   target_uid: int,
   *   action: string,
   *   reason: string,
   *   old_ends: int|null,
   *   new_ends: int|null
   *   }>
   *   Audit rows.
   */
  public function slice(int $actor, int $target, string $action, int $from, int $to, int $limit, int $offset): array {
    $query = $this->filtered($actor, $target, $action, $from, $to);
    $query->fields('l', [
      'created',
      'actor_uid',
      'target_uid',
      'action',
      'reason',
      'old_ends',
      'new_ends',
    ]);
    $query->orderBy('l.id', 'DESC');
    $query->range(max(0, $offset), max(1, $limit));
    $statement = $query->execute();
    if ($statement === NULL) {
      return [];
    }
    $rows = [];
    foreach ($statement as $record) {
      if (!is_object($record)) {
        continue;
      }
      $rows[] = [
        'created' => is_numeric($record->created) ? (int) $record->created : 0,
        'actor_uid' => is_numeric($record->actor_uid) ? (int) $record->actor_uid : 0,
        'target_uid' => is_numeric($record->target_uid) ? (int) $record->target_uid : 0,
        'action' => (string) $record->action,
        'reason' => (string) $record->reason,
        'old_ends' => is_numeric($record->old_ends) ? (int) $record->old_ends : NULL,
        'new_ends' => is_numeric($record->new_ends) ? (int) $record->new_ends : NULL,
      ];
    }
    return $rows;
  }

  /**
   * Select for the audit filters. Zero means that filter is unused.
   */
  private function filtered(int $actor, int $target, string $action, int $from, int $to): SelectInterface {
    $query = $this->database->select('lifebalance_entitlement_log', 'l');
    if ($actor > 0) {
      $query->condition('l.actor_uid', $actor);
    }
    if ($target > 0) {
      $query->condition('l.target_uid', $target);
    }
    if ($this->allows($action)) {
      $query->condition('l.action', $action);
    }
    if ($from > 0) {
      $query->condition('l.created', $from, '>=');
    }
    if ($to > 0) {
      $query->condition('l.created', $to, '<=');
    }
    return $query;
  }

}
