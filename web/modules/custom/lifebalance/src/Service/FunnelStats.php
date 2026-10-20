<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Database\Connection;

/**
 * Funnel counts from our own tables. Scores, notes, and answers are not read.
 *
 * The wheel flag is stored as a serialized user.data value, so that count is
 * everyone who created a wheel, not only the selected period.
 */
final class FunnelStats {

  /**
   * A later snapshot counts as a return after this many seconds.
   */
  private const RETURN_GAP = 30 * 86400;

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * People at each step. Every value is a count.
   *
   * @return array{
   *   registered: int,
   *   wheel: int,
   *   first_snapshot: int,
   *   trial: int,
   *   returned: int
   *   }
   *   Counts only.
   */
  public function counts(int $since): array {
    return [
      'registered' => $this->registered($since),
      'wheel' => $this->wheel(),
      'first_snapshot' => $this->firstSnapshot($since),
      'trial' => $this->trial($since),
      'returned' => $this->returned($since),
    ];
  }

  /**
   * Accounts registered in the period.
   */
  private function registered(int $since): int {
    $query = $this->database->select('users_field_data', 'u');
    $query->condition('u.default_langcode', 1);
    $query->condition('u.uid', 0, '>');
    if ($since > 0) {
      $query->condition('u.created', $since, '>=');
    }
    return $this->integer($query->countQuery()->execute()?->fetchField());
  }

  /**
   * Accounts that have created a wheel.
   */
  private function wheel(): int {
    if (!$this->database->schema()->tableExists('users_data')) {
      return 0;
    }
    $query = $this->database->select('users_data', 'd');
    $query->condition('d.module', WheelCreation::MODULE);
    $query->condition('d.name', WheelCreation::NAME);
    $query->condition('d.uid', 0, '>');
    $query->addExpression('COUNT(DISTINCT d.uid)', 'people');
    return $this->integer($query->execute()?->fetchField());
  }

  /**
   * Accounts whose earliest snapshot falls in the period.
   */
  private function firstSnapshot(int $since): int {
    if (!$this->database->schema()->tableExists('lifebalance_snapshot')) {
      return 0;
    }
    $query = $this->database->select('lifebalance_snapshot', 's');
    $query->addField('s', 'uid');
    $query->condition('s.uid', 0, '>');
    $query->groupBy('s.uid');
    if ($since > 0) {
      $query->having('MIN(s.created) >= :since', [':since' => $since]);
    }
    return $this->integer($query->countQuery()->execute()?->fetchField());
  }

  /**
   * Accounts that started the one trial. The row is the source of truth.
   */
  private function trial(int $since): int {
    if (!$this->database->schema()->tableExists('lifebalance_trial_usage')) {
      return 0;
    }
    $query = $this->database->select('lifebalance_trial_usage', 't');
    $query->condition('t.uid', 0, '>');
    if ($since > 0) {
      $query->condition('t.used_at', $since, '>=');
    }
    return $this->integer($query->countQuery()->execute()?->fetchField());
  }

  /**
   * Accounts with a snapshot at least 30 days after their first.
   */
  private function returned(int $since): int {
    if (!$this->database->schema()->tableExists('lifebalance_snapshot')) {
      return 0;
    }
    $query = $this->database->select('lifebalance_snapshot', 's');
    $query->addField('s', 'uid');
    $query->condition('s.uid', 0, '>');
    $query->groupBy('s.uid');
    $query->having('MAX(s.created) >= MIN(s.created) + :gap', [
      ':gap' => self::RETURN_GAP,
    ]);
    if ($since > 0) {
      $query->having('MAX(s.created) >= :since', [':since' => $since]);
    }
    return $this->integer($query->countQuery()->execute()?->fetchField());
  }

  /**
   * A numeric SQL result, or zero.
   */
  private function integer(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

}
