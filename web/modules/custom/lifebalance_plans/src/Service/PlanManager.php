<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Starts and closes the single active 30-day plan.
 *
 * The active check runs inside a database transaction. Goals store a copy
 * and keep their text when the recommendation changes. Completing a plan
 * does not change scores.
 */
final class PlanManager {

  /**
   * Goals created with the plan. Later goals still share the active limit.
   */
  public const START_LIMIT = 3;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementCheckerInterface $entitlementChecker,
    private readonly EntitlementManager $entitlements,
    private readonly GoalManager $goals,
    private readonly SectorRepository $sectors,
    private readonly Clock $clock,
    private readonly MailManagerInterface $mailManager,
    private readonly UserDataInterface $userData,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Opens a plan and copies up to three recommendations into goals.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who will own the plan.
   * @param list<string> $sector_ids
   *   One or two sectors the person chose.
   * @param list<string> $keys
   *   One to three published recommendation keys.
   */
  public function start(AccountInterface $account, array $sector_ids, array $keys): PlanResult {
    $uid = (int) $account->id();
    if ($uid < 1 || !$this->entitlementChecker->hasFeatureAccess($account, 'plan_30')) {
      return new PlanResult(PlanResult::PREMIUM);
    }
    if (!$this->entitlementChecker->hasFeatureAccess($account, 'goals')) {
      return new PlanResult(PlanResult::PREMIUM);
    }
    $sector_ids = array_values(array_unique($sector_ids));
    $keys = array_values(array_unique($keys));
    if (count($sector_ids) < 1 || count($sector_ids) > 2 || count($keys) < 1 || count($keys) > self::START_LIMIT) {
      return new PlanResult(PlanResult::INVALID);
    }
    $recommendations = $this->recommendations($keys, $sector_ids);
    if (count($recommendations) !== count($keys)) {
      return new PlanResult(PlanResult::INVALID);
    }
    if ($this->goals->limit() - $this->activeGoalCount($uid) < count($keys)) {
      return new PlanResult(PlanResult::LIMIT);
    }
    $transaction = $this->database->startTransaction();
    try {
      if ($this->lockedActiveCount($uid, NULL) > 0) {
        return new PlanResult(PlanResult::ACTIVE);
      }
      $starts = $this->clock->now();
      $plan = Plan::create([
        'uid' => $uid,
        'focus_sectors' => $sector_ids,
        'starts' => $starts,
        'ends' => $this->entitlements->endOfDays($starts, Plan::DAYS),
        'status' => Plan::STATUS_ACTIVE,
        'invite_open' => 0,
        'completion_mailed' => 0,
      ]);
      $plan->save();
      $plan_id = (int) $plan->id();
      foreach ($keys as $key) {
        $created = $this->goals->fromRecommendation($account, $key, $plan_id);
        if ($created->code !== GoalResult::SAVED) {
          throw new EntityStorageException('A plan goal was not stored.');
        }
      }
    }
    catch (EntityStorageException $exception) {
      $transaction->rollBack();
      if (str_contains($exception->getMessage(), 'active plan')) {
        return new PlanResult(PlanResult::ACTIVE);
      }
      return new PlanResult(PlanResult::INVALID);
    }
    $this->loggerFactory->get('lifebalance_plans')->info('Started a 30-day plan for uid @uid.', [
      '@uid' => $uid,
    ]);
    return new PlanResult(PlanResult::SAVED, $plan);
  }

  /**
   * Closes the active plan early. Goals stay.
   */
  public function abandon(AccountInterface $account): PlanResult {
    $plan = $this->active($account);
    if (!$plan instanceof Plan) {
      return new PlanResult(PlanResult::INVALID);
    }
    if ((int) $plan->getOwnerId() !== (int) $account->id()) {
      return new PlanResult(PlanResult::INVALID);
    }
    $plan->set('status', Plan::STATUS_ABANDONED);
    $plan->save();
    return new PlanResult(PlanResult::SAVED, $plan);
  }

  /**
   * Marks ended plans completed and sends the letter once.
   */
  public function completeDue(): int {
    $now = $this->clock->now();
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', Plan::STATUS_ACTIVE)
      ->condition('ends', $now, '<=')
      ->execute();
    $count = 0;
    foreach ($storage->loadMultiple($ids) as $plan) {
      if (!$plan instanceof Plan) {
        continue;
      }
      $plan->set('status', Plan::STATUS_COMPLETED);
      $plan->set('invite_open', 1);
      $plan->appliedBySystem = TRUE;
      $plan->save();
      $count++;
      $this->loggerFactory->get('lifebalance_plans')->info('Completed plan @id for uid @uid.', [
        '@id' => $plan->id(),
        '@uid' => $plan->getOwnerId(),
      ]);
    }
    $this->notifyCompleted();
    return $count;
  }

  /**
   * The person's active plan, or NULL.
   */
  public function active(AccountInterface $account): ?Plan {
    return $this->one((int) $account->id(), Plan::STATUS_ACTIVE);
  }

  /**
   * Plans that are no longer active, newest end first.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Plan>
   *   Archived plans.
   */
  public function archive(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', Plan::STATUS_ACTIVE, '<>')
      ->sort('ends', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    $plans = [];
    foreach ($storage->loadMultiple($ids) as $plan) {
      if ($plan instanceof Plan && (int) $plan->getOwnerId() === $uid) {
        $plans[] = $plan;
      }
    }
    return $plans;
  }

  /**
   * Goals copied into this plan.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Goal>
   *   Goals in creation order.
   */
  public function goals(Plan $plan): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_goal');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $plan->getOwnerId())
      ->condition('plan_id', $plan->id())
      ->sort('id', 'ASC')
      ->execute();
    $goals = [];
    foreach ($storage->loadMultiple($ids) as $goal) {
      if ($goal instanceof Goal && (int) $goal->getOwnerId() === (int) $plan->getOwnerId()) {
        $goals[] = $goal;
      }
    }
    return $goals;
  }

  /**
   * Whether a completed plan is still waiting for a new self-assessment.
   */
  public function invitationOpen(AccountInterface $account): bool {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('invite_open', 1)
      ->count()
      ->execute();
    return (int) $count > 0;
  }

  /**
   * Focus sectors of plans that still invite a new self-assessment.
   *
   * @return list<string>
   *   Sector IDs in catalog order.
   */
  public function invitationSectors(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('invite_open', 1)
      ->execute();
    $chosen = [];
    foreach ($storage->loadMultiple($ids) as $plan) {
      if (!$plan instanceof Plan || (int) $plan->getOwnerId() !== $uid) {
        continue;
      }
      foreach ($plan->getFocusSectors() as $sector_id) {
        $chosen[$sector_id] = TRUE;
      }
    }
    $ordered = [];
    foreach (array_keys($this->sectors->loadActive()) as $id) {
      if (isset($chosen[$id])) {
        $ordered[] = $id;
      }
    }
    return $ordered;
  }

  /**
   * A new snapshot answers the completion invitation.
   */
  public function clearInvite(int $uid): void {
    if ($uid < 1) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('invite_open', 1)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $plan) {
      if (!$plan instanceof Plan) {
        continue;
      }
      $plan->set('invite_open', 0);
      $plan->save();
    }
  }

  /**
   * Refuses a second active plan. Called from presave inside the save.
   */
  public function assertSingleActive(Plan $plan): void {
    if ($plan->getStatus() !== Plan::STATUS_ACTIVE) {
      return;
    }
    $id = $plan->isNew() ? NULL : (int) $plan->id();
    if ($this->lockedActiveCount((int) $plan->getOwnerId(), $id) > 0) {
      throw new EntityStorageException('An active plan already exists.');
    }
  }

  /**
   * Whole days from today until the Kyiv end date. Zero on the last day.
   */
  public function daysLeft(Plan $plan): int {
    $zone = new \DateTimeZone('Europe/Kyiv');
    $today = (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone($zone);
    $end = (new \DateTimeImmutable('@' . $plan->getEnds()))->setTimezone($zone);
    $today = $today->setTime(0, 0);
    $end = $end->setTime(0, 0);
    if ($end <= $today) {
      return 0;
    }
    return (int) $today->diff($end)->days;
  }

  /**
   * Active plans for this uid, locking the matching rows.
   */
  private function lockedActiveCount(int $uid, ?int $except_id): int {
    if ($uid < 1) {
      return 0;
    }
    $query = $this->database->select('lifebalance_plan', 'p');
    $query->addExpression('COUNT(p.id)', 'total');
    $query->condition('uid', $uid);
    $query->condition('status', Plan::STATUS_ACTIVE);
    if ($except_id !== NULL && $except_id > 0) {
      $query->condition('id', $except_id, '<>');
    }
    $query->forUpdate();
    return (int) $query->execute()->fetchField();
  }

  /**
   * Published recommendations whose sectors were chosen.
   *
   * @param list<string> $keys
   *   Catalog keys.
   * @param list<string> $sector_ids
   *   Chosen sectors.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Recommendation>
   *   Matches, or a short list when one key is rejected.
   */
  private function recommendations(array $keys, array $sector_ids): array {
    $found = [];
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    foreach ($keys as $key) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('key', $key)
        ->range(0, 1)
        ->execute();
      $id = reset($ids);
      $entity = $id === FALSE ? NULL : $storage->load($id);
      if (!$entity instanceof Recommendation) {
        return [];
      }
      if ($entity->getStatus() !== Recommendation::STATUS_PUBLISHED) {
        return [];
      }
      if ($entity->getSafety() === Recommendation::SAFETY_SENSITIVE) {
        return [];
      }
      if (!in_array($entity->getSectorId(), $sector_ids, TRUE) || !$this->activeSector($entity->getSectorId())) {
        return [];
      }
      $found[] = $entity;
    }
    return $found;
  }

  /**
   * Whether this sector is currently active.
   */
  private function activeSector(string $sector): bool {
    return ($this->sectors->loadActive()[$sector] ?? NULL) instanceof Sector;
  }

  /**
   * Active goals of one person.
   */
  private function activeGoalCount(int $uid): int {
    $storage = $this->entityTypeManager->getStorage('lifebalance_goal');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', [Goal::STATUS_PLANNED, Goal::STATUS_IN_PROGRESS], 'IN')
      ->count()
      ->execute();
  }

  /**
   * One plan of a status, or NULL.
   */
  private function one(int $uid, string $status): ?Plan {
    if ($uid < 1) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', $status)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    $plan = $id === FALSE ? NULL : $storage->load($id);
    return $plan instanceof Plan ? $plan : NULL;
  }

  /**
   * One completion letter, unless the person refused service reminders.
   */
  private function notifyCompleted(): void {
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', Plan::STATUS_COMPLETED)
      ->condition('completion_mailed', 0)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $plan) {
      if (!$plan instanceof Plan) {
        continue;
      }
      $uid = (int) $plan->getOwnerId();
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $sent = !$account instanceof UserInterface || $this->muted($uid) || $this->send($account);
      if (!$sent) {
        continue;
      }
      $plan->set('completion_mailed', 1);
      $plan->appliedBySystem = TRUE;
      $plan->save();
    }
  }

  /**
   * Whether this person asked not to receive service reminders.
   */
  private function muted(int $uid): bool {
    $value = $this->userData->get('lifebalance', $uid, EntitlementNotifier::MUTE_NAME);
    return $value === 1 || $value === '1' || $value === TRUE;
  }

  /**
   * Sends the completion letter. The body has no scores and no goal text.
   */
  private function send(UserInterface $account): bool {
    $mail = $account->getEmail();
    if ($mail === NULL || $mail === '') {
      return TRUE;
    }
    $result = $this->mailManager->mail(
      'lifebalance_plans',
      'plan_completed',
      $mail,
      $account->getPreferredLangcode(),
    );
    return !empty($result['result']);
  }

}
