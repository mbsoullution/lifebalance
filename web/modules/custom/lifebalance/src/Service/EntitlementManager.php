<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Every change of access. Each change is one transaction and one log row.
 *
 * Payment records are created here. Billing revokes them through
 * revokeForRefund(). Manual grants stay on grantManual(), extend(),
 * shorten(), and revoke().
 */
final class EntitlementManager {

  private const ZONE = 'Europe/Kyiv';

  /**
   * Length of a self-serve trial.
   */
  public const TRIAL_DAYS = 7;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementLog $entitlementLog,
    private readonly Connection $database,
    private readonly Clock $clock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Start of a new payment, or NULL when an open-ended grant blocks it.
   *
   * A record that starts later still counts. A second purchase during a
   * trial then extends the first paid period instead of covering it.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who would pay.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function nextPaidStart(AccountInterface $account, ?int $at = NULL): ?int {
    $at = $at ?? $this->clock->now();
    $start = $at;
    foreach ($this->unexpired($account, $at) as $entitlement) {
      $ends = $entitlement->getEnds();
      if ($ends === NULL) {
        return NULL;
      }
      if ($ends > $start) {
        $start = $ends;
      }
    }
    return $start;
  }

  /**
   * End of the Nth Kyiv calendar day that contains the start instant.
   *
   * Day 1 is the Kyiv date of $starts. The result is 23:59:59 that day
   * when $days is 1.
   */
  public function endOfDays(int $starts, int $days): int {
    if ($days < 1) {
      throw new \InvalidArgumentException('The number of days is invalid.');
    }
    $zone = new \DateTimeZone(self::ZONE);
    $start = (new \DateTimeImmutable('@' . $starts))->setTimezone($zone);
    $end = $start->modify('+' . ($days - 1) . ' days')->setTime(23, 59, 59);
    return $end->getTimestamp();
  }

  /**
   * Kyiv end of one calendar date, or NULL when the value is not a date.
   */
  public function endOfKyivDate(string $value): ?int {
    $date = \DateTimeImmutable::createFromFormat(
      '!Y-m-d',
      $value,
      new \DateTimeZone(self::ZONE),
    );
    if ($date === FALSE) {
      return NULL;
    }
    return $date->setTime(23, 59, 59)->getTimestamp();
  }

  /**
   * Starts the one self-serve trial, or reports why it cannot start.
   *
   * The usage insert is the lock. A duplicate primary key rolls the new
   * record back, so two clicks leave a single trial.
   */
  public function startTrial(AccountInterface $account): TrialResult {
    $uid = (int) $account->id();
    if ($uid < 1 || !$this->trialEnabled()) {
      return new TrialResult(TrialResult::DISABLED);
    }
    if ($this->snapshotCount($uid) < 1) {
      return new TrialResult(TrialResult::NO_SNAPSHOT);
    }
    $starts = $this->clock->now();
    $ends = $this->endOfDays($starts, self::TRIAL_DAYS);
    $transaction = $this->database->startTransaction();
    try {
      $entitlement = $this->createRecord(
        $account,
        Entitlement::SOURCE_TRIAL,
        $starts,
        $ends,
        'Самостійне ознайомлення',
        $uid,
        FALSE,
        NULL,
        EntitlementLog::TRIAL_START,
      );
      $this->database->insert('lifebalance_trial_usage')
        ->fields([
          'uid' => $uid,
          'used_at' => $starts,
          'entitlement_id' => (int) $entitlement->id(),
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      if ($this->isDuplicateTrial($exception)) {
        return new TrialResult(TrialResult::USED);
      }
      throw $exception;
    }
    return new TrialResult(TrialResult::SAVED, $entitlement);
  }

  /**
   * Why the button stays hidden, or NULL when the person may start.
   */
  public function trialBlock(AccountInterface $account): ?string {
    $uid = (int) $account->id();
    if ($uid < 1 || !$this->trialEnabled()) {
      return TrialResult::DISABLED;
    }
    if ($this->snapshotCount($uid) < 1) {
      return TrialResult::NO_SNAPSHOT;
    }
    if ($this->trialUsed($uid)) {
      return TrialResult::USED;
    }
    return NULL;
  }

  /**
   * Whether the confirm page and the button may be shown.
   */
  public function trialAvailable(AccountInterface $account): bool {
    return $this->trialBlock($account) === NULL;
  }

  /**
   * Records that the one-day trial reminder was handled.
   */
  public function markTrialReminded(Entitlement $entitlement): void {
    if ($entitlement->getSource() !== Entitlement::SOURCE_TRIAL) {
      return;
    }
    $id = (int) $entitlement->id();
    if ($id < 1 || $this->trialReminded($id)) {
      return;
    }
    $ends = $entitlement->getEnds();
    $status = $entitlement->getStatus();
    $transaction = $this->database->startTransaction();
    try {
      $this->entitlementLog->append(
        $id,
        (int) $entitlement->getOwnerId(),
        NULL,
        EntitlementLog::TRIAL_REMIND,
        $ends,
        $ends,
        $status,
        $status,
        '',
        $this->clock->now(),
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Creates an admin or trial record.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who receives access.
   * @param string $source
   *   Manual source, admin_grant or trial.
   * @param int $starts
   *   Start instant.
   * @param int|null $ends
   *   End instant, or NULL for no end.
   * @param string $reason
   *   Why the record exists.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who performed the change.
   * @param bool $notifyUser
   *   Whether a later step should mail the person.
   */
  public function grantManual(
    AccountInterface $account,
    string $source,
    int $starts,
    ?int $ends,
    string $reason,
    AccountInterface $actor,
    bool $notifyUser,
  ): Entitlement {
    if (!in_array($source, [Entitlement::SOURCE_ADMIN, Entitlement::SOURCE_TRIAL], TRUE)) {
      throw new \InvalidArgumentException('This grant source is not manual.');
    }
    $this->assertAccount($account);
    $this->assertActor($actor);
    $reason = $this->assertReason($reason);
    $this->assertWindow($starts, $ends);
    return $this->createRecord(
      $account,
      $source,
      $starts,
      $ends,
      $reason,
      (int) $actor->id(),
      $notifyUser,
      NULL,
      EntitlementLog::GRANT,
    );
  }

  /**
   * Creates an admin or trial record.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who receives access.
   * @param string $source
   *   Manual source, admin_grant or trial.
   * @param int $starts
   *   Start instant.
   * @param int|null $ends
   *   End instant, or NULL for no end.
   * @param string $reason
   *   Why the record exists.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who performed the change.
   * @param bool $notifyUser
   *   Whether a later step should mail the person.
   */
  public function grant(
    AccountInterface $account,
    string $source,
    int $starts,
    ?int $ends,
    string $reason,
    AccountInterface $actor,
    bool $notifyUser,
  ): Entitlement {
    return $this->grantManual(
      $account,
      $source,
      $starts,
      $ends,
      $reason,
      $actor,
      $notifyUser,
    );
  }

  /**
   * Creates the single record for one payment order.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who paid.
   * @param int $orderId
   *   The order id. A second call with the same id is refused.
   * @param int|null $ends
   *   End instant, or NULL for no end.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function activatePaid(AccountInterface $account, int $orderId, ?int $ends, ?int $at = NULL): Entitlement {
    $this->assertAccount($account);
    if ($orderId < 1) {
      throw new \InvalidArgumentException('A payment needs an order id.');
    }
    if ($this->orderExists($orderId)) {
      throw new \InvalidArgumentException('This order already granted access.');
    }
    $starts = $this->nextPaidStart($account, $at);
    if ($starts === NULL) {
      throw new \InvalidArgumentException('An open-ended entitlement blocks a new payment.');
    }
    $this->assertWindow($starts, $ends);
    $reason = 'order:' . $orderId;
    return $this->createRecord(
      $account,
      Entitlement::SOURCE_PAYMENT,
      $starts,
      $ends,
      $reason,
      NULL,
      FALSE,
      $orderId,
      EntitlementLog::PAID_ACTIVATE,
    );
  }

  /**
   * Creates the single record for one payment order.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who paid.
   * @param int $orderId
   *   The order id. A second call with the same id is refused.
   * @param int|null $ends
   *   End instant, or NULL for no end.
   * @param int|null $at
   *   Unix time. NULL means now.
   */
  public function grantFromOrder(AccountInterface $account, int $orderId, ?int $ends, ?int $at = NULL): Entitlement {
    return $this->activatePaid($account, $orderId, $ends, $at);
  }

  /**
   * Moves the end later, or removes the end.
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $entitlement
   *   A manual record.
   * @param int|null $ends
   *   The new end, or NULL for no end.
   * @param string $reason
   *   Why the end moved.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who performed the change.
   */
  public function extend(Entitlement $entitlement, ?int $ends, string $reason, AccountInterface $actor): Entitlement {
    $this->assertManual($entitlement);
    $this->assertActor($actor);
    $reason = $this->assertReason($reason);
    $current = $entitlement->getEnds();
    if ($current === NULL) {
      throw new \InvalidArgumentException('An open-ended entitlement cannot be extended.');
    }
    if ($ends !== NULL && $ends <= $current) {
      throw new \InvalidArgumentException('The new end is not later.');
    }
    $this->assertWindow($entitlement->getStarts(), $ends);
    return $this->changeEnd($entitlement, $ends, $reason, (int) $actor->id(), EntitlementLog::EXTEND);
  }

  /**
   * Moves the end earlier, but not before now.
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $entitlement
   *   A manual record.
   * @param int $ends
   *   The new end.
   * @param string $reason
   *   Why the end moved.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who performed the change.
   */
  public function shorten(Entitlement $entitlement, int $ends, string $reason, AccountInterface $actor): Entitlement {
    $this->assertManual($entitlement);
    $this->assertActor($actor);
    $reason = $this->assertReason($reason);
    $now = $this->clock->now();
    if ($ends < $now) {
      throw new \InvalidArgumentException('The new end is already past.');
    }
    $current = $entitlement->getEnds();
    if ($current !== NULL && $ends >= $current) {
      throw new \InvalidArgumentException('The new end is not earlier.');
    }
    $this->assertWindow($entitlement->getStarts(), $ends);
    return $this->changeEnd($entitlement, $ends, $reason, (int) $actor->id(), EntitlementLog::SHORTEN);
  }

  /**
   * Marks a manual record revoked.
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $entitlement
   *   A manual record.
   * @param string $reason
   *   Why access stopped.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Who performed the change.
   */
  public function revoke(Entitlement $entitlement, string $reason, AccountInterface $actor): Entitlement {
    $this->assertManual($entitlement);
    $this->assertActor($actor);
    $reason = $this->assertReason($reason);
    if ($entitlement->getStatus() === Entitlement::STATUS_REVOKED) {
      throw new \InvalidArgumentException('This entitlement is already revoked.');
    }
    return $this->changeStatus($entitlement, Entitlement::STATUS_REVOKED, $reason, (int) $actor->id(), EntitlementLog::REVOKE);
  }

  /**
   * Revokes the record created by one payment.
   *
   * Billing is the only caller. A manual grant uses revoke().
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $entitlement
   *   A payment record.
   * @param string $reason
   *   Why access stopped.
   */
  public function revokeForRefund(Entitlement $entitlement, string $reason): Entitlement {
    if ($entitlement->getSource() !== Entitlement::SOURCE_PAYMENT || $entitlement->getOrderId() === NULL) {
      throw new \InvalidArgumentException('Only a payment entitlement can be revoked here.');
    }
    $reason = $this->assertReason($reason);
    if ($entitlement->getStatus() === Entitlement::STATUS_REVOKED) {
      throw new \InvalidArgumentException('This entitlement is already revoked.');
    }
    return $this->changeStatus($entitlement, Entitlement::STATUS_REVOKED, $reason, NULL, EntitlementLog::REFUND_REVOKE);
  }

  /**
   * Revokes access that a payment created.
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $entitlement
   *   A payment record.
   * @param string $reason
   *   Why access stopped.
   */
  public function revokePayment(Entitlement $entitlement, string $reason): Entitlement {
    return $this->revokeForRefund($entitlement, $reason);
  }

  /**
   * Records that the expiry letter was sent.
   */
  public function markExpiryNotified(Entitlement $entitlement): void {
    if ((int) $entitlement->get('expiry_notified')->value === 1) {
      return;
    }
    $old_status = $entitlement->getStatus();
    $ends = $entitlement->getEnds();
    $transaction = $this->database->startTransaction();
    try {
      $entitlement->set('expiry_notified', TRUE);
      $entitlement->save();
      $this->entitlementLog->append(
        (int) $entitlement->id(),
        (int) $entitlement->getOwnerId(),
        NULL,
        EntitlementLog::EXPIRE_NOTICE,
        $ends,
        $ends,
        $old_status,
        $old_status,
        '',
        $this->clock->now(),
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    $this->log('Noted expiry mail for entitlement @id uid @uid.', $entitlement);
  }

  /**
   * Stores a new end and one log row.
   */
  private function changeEnd(Entitlement $entitlement, ?int $ends, string $reason, int $actorId, string $action): Entitlement {
    $old_ends = $entitlement->getEnds();
    $status = $entitlement->getStatus();
    $transaction = $this->database->startTransaction();
    try {
      $entitlement->set('ends', $ends);
      $entitlement->set('reason', $reason);
      $entitlement->set('expiry_notified', FALSE);
      $entitlement->save();
      $this->entitlementLog->append(
        (int) $entitlement->id(),
        (int) $entitlement->getOwnerId(),
        $actorId,
        $action,
        $old_ends,
        $ends,
        $status,
        $status,
        $reason,
        $this->clock->now(),
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    $this->log('Updated entitlement @id for uid @uid.', $entitlement);
    return $entitlement;
  }

  /**
   * Stores a new status and one log row.
   */
  private function changeStatus(Entitlement $entitlement, string $status, string $reason, ?int $actorId, string $action): Entitlement {
    $old_status = $entitlement->getStatus();
    $ends = $entitlement->getEnds();
    $transaction = $this->database->startTransaction();
    try {
      $entitlement->set('status', $status);
      $entitlement->set('reason', $reason);
      $entitlement->save();
      $this->entitlementLog->append(
        (int) $entitlement->id(),
        (int) $entitlement->getOwnerId(),
        $actorId,
        $action,
        $ends,
        $ends,
        $old_status,
        $status,
        $reason,
        $this->clock->now(),
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    $this->log('Updated entitlement @id for uid @uid.', $entitlement);
    return $entitlement;
  }

  /**
   * Creates a record and stores the first log row.
   */
  private function createRecord(
    AccountInterface $account,
    string $source,
    int $starts,
    ?int $ends,
    string $reason,
    ?int $actorId,
    bool $notifyUser,
    ?int $orderId,
    string $action,
  ): Entitlement {
    $entitlement = Entitlement::create([
      'uid' => $account->id(),
      'feature_set' => Entitlement::FEATURE_PREMIUM,
      'source' => $source,
      'starts' => $starts,
      'ends' => $ends,
      'status' => Entitlement::STATUS_ACTIVE,
      'reason' => $reason,
      'granted_by' => $actorId,
      'order_id' => $orderId,
      'notify_user' => $notifyUser,
      'expiry_notified' => FALSE,
    ]);
    $transaction = $this->database->startTransaction();
    try {
      $entitlement->save();
      $this->entitlementLog->append(
        (int) $entitlement->id(),
        (int) $account->id(),
        $actorId,
        $action,
        NULL,
        $ends,
        NULL,
        Entitlement::STATUS_ACTIVE,
        $reason,
        $this->clock->now(),
      );
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    $this->log('Granted entitlement @id for uid @uid.', $entitlement);
    return $entitlement;
  }

  /**
   * Returns a trimmed reason, or refuses an empty or oversized one.
   */
  private function assertReason(string $reason): string {
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) {
      throw new \InvalidArgumentException('A reason is required.');
    }
    return $reason;
  }

  /**
   * Refuses a window that ends before it starts.
   */
  private function assertWindow(int $starts, ?int $ends): void {
    if ($starts < 0 || ($ends !== NULL && $ends <= $starts)) {
      throw new \InvalidArgumentException('The entitlement window is invalid.');
    }
  }

  /**
   * Refuses a change to a payment or promo record.
   */
  private function assertManual(Entitlement $entitlement): void {
    if (!$entitlement->isManual()) {
      throw new \InvalidArgumentException('Only a manual entitlement can be changed here.');
    }
  }

  /**
   * Refuses an anonymous target.
   */
  private function assertAccount(AccountInterface $account): void {
    if ((int) $account->id() < 1) {
      throw new \InvalidArgumentException('This account cannot receive access.');
    }
  }

  /**
   * Refuses an anonymous actor.
   */
  private function assertActor(AccountInterface $actor): void {
    if ((int) $actor->id() < 1) {
      throw new \InvalidArgumentException('This account cannot change access.');
    }
  }

  /**
   * Active records that have not ended, including ones that start later.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who would pay.
   * @param int $at
   *   Unix time.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Records with time still left.
   */
  private function unexpired(AccountInterface $account, int $at): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('feature_set', Entitlement::FEATURE_PREMIUM)
      ->condition('status', Entitlement::STATUS_ACTIVE)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $records = [];
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if (!$entitlement instanceof Entitlement) {
        continue;
      }
      $ends = $entitlement->getEnds();
      if ($ends !== NULL && $ends <= $at) {
        continue;
      }
      $records[] = $entitlement;
    }
    return $records;
  }

  /**
   * Whether this order id is already stored.
   */
  private function orderExists(int $orderId): bool {
    $ids = $this->entityTypeManager->getStorage('lifebalance_entitlement')->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $orderId)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Writes a uid-only log line.
   */
  private function log(string $message, Entitlement $entitlement): void {
    $this->loggerFactory->get('lifebalance')->info($message, [
      '@id' => $entitlement->id(),
      '@uid' => $entitlement->getOwnerId(),
    ]);
  }

  /**
   * The site switch for self-serve trial. Missing config stays off.
   */
  private function trialEnabled(): bool {
    return (bool) $this->configFactory->get('lifebalance.settings')->get('trial_enabled');
  }

  /**
   * Snapshots owned by this account. Scores are not read.
   */
  private function snapshotCount(int $uid): int {
    if (!$this->database->schema()->tableExists('lifebalance_snapshot')) {
      return 0;
    }
    $query = $this->database->select('lifebalance_snapshot', 's');
    $query->condition('s.uid', $uid);
    $value = $query->countQuery()->execute()?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Whether the one-trial row already exists.
   */
  private function trialUsed(int $uid): bool {
    if (!$this->database->schema()->tableExists('lifebalance_trial_usage')) {
      return FALSE;
    }
    $query = $this->database->select('lifebalance_trial_usage', 't');
    $query->condition('t.uid', $uid);
    $value = $query->countQuery()->execute()?->fetchField();
    return is_numeric($value) && (int) $value > 0;
  }

  /**
   * Whether the one-day reminder was already handled.
   */
  private function trialReminded(int $entitlement_id): bool {
    $query = $this->database->select('lifebalance_entitlement_log', 'l');
    $query->condition('l.entitlement_id', $entitlement_id);
    $query->condition('l.action', EntitlementLog::TRIAL_REMIND);
    $value = $query->countQuery()->execute()?->fetchField();
    return is_numeric($value) && (int) $value > 0;
  }

  /**
   * Whether the failure is the one-trial primary key.
   */
  private function isDuplicateTrial(\Throwable $exception): bool {
    while (!$exception instanceof IntegrityConstraintViolationException) {
      $previous = $exception->getPrevious();
      if (!$previous instanceof \Throwable) {
        return FALSE;
      }
      $exception = $previous;
    }
    return TRUE;
  }

}
