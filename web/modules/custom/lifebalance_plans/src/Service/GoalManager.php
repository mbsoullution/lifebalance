<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\lifebalance_plans\Repository\GoalRepository;

/**
 * Creates goals and updates their status and steps.
 *
 * A goal stores a copy. A later recommendation revision does not rewrite it.
 * New goals require Premium. Status and steps of an existing goal do not.
 */
final class GoalManager {

  /**
   * Product cap. Configuration may only lower it.
   */
  private const MAX_LIMIT = 10;

  public function __construct(
    private readonly GoalRepository $goals,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementCheckerInterface $entitlementChecker,
    private readonly SectorRepository $sectors,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Clock $clock,
  ) {}

  /**
   * Copies one published recommendation into a new goal.
   */
  public function fromRecommendation(AccountInterface $account, string $key, ?int $plan_id = NULL): GoalResult {
    $recommendation = $this->recommendation($key);
    if (!$recommendation instanceof Recommendation) {
      return new GoalResult(GoalResult::MISSING);
    }
    if ($recommendation->getStatus() !== Recommendation::STATUS_PUBLISHED) {
      return new GoalResult(GoalResult::UNPUBLISHED);
    }
    if ($recommendation->getSafety() === Recommendation::SAFETY_SENSITIVE) {
      return new GoalResult(GoalResult::SENSITIVE);
    }
    $blocked = $this->creationBlock($account, (int) $recommendation->id());
    if ($blocked !== NULL) {
      return new GoalResult($blocked);
    }
    $steps = [];
    foreach ($recommendation->getSteps() as $text) {
      $steps[] = ['text' => $text, 'done' => 0, 'done_at' => NULL];
    }
    $goal = Goal::create([
      'uid' => $account->id(),
      'sector' => $recommendation->getSectorId(),
      'origin' => Goal::ORIGIN_RECOMMENDATION,
      'recommendation_id' => $recommendation->id(),
      'recommendation_revision_id' => $recommendation->getRevisionId(),
      'plan_id' => $plan_id,
      'title' => $recommendation->getTitle(),
      'instructions' => $recommendation->getBody(),
      'steps' => $steps,
      'status' => Goal::STATUS_PLANNED,
    ]);
    return $this->store($goal);
  }

  /**
   * Stores a goal the person wrote.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person who will own the goal.
   * @param string $sector
   *   Active sector ID.
   * @param string $title
   *   Short title.
   * @param string $instructions
   *   Description.
   * @param list<string> $steps
   *   One to five step texts.
   * @param string|null $due
   *   Due date as Y-m-d, or NULL.
   */
  public function createOwn(
    AccountInterface $account,
    string $sector,
    string $title,
    string $instructions,
    array $steps,
    ?string $due,
  ): GoalResult {
    if (!$this->activeSector($sector)) {
      return new GoalResult(GoalResult::INVALID);
    }
    $title = trim($title);
    $instructions = trim($instructions);
    if ($title === '' || mb_strlen($title) > 80 || $instructions === '' || mb_strlen($instructions) > 2000) {
      return new GoalResult(GoalResult::INVALID);
    }
    $items = [];
    foreach ($steps as $step) {
      $text = trim($step);
      if ($text === '') {
        continue;
      }
      if (mb_strlen($text) > 255) {
        return new GoalResult(GoalResult::INVALID);
      }
      $items[] = ['text' => $text, 'done' => 0, 'done_at' => NULL];
    }
    if ($items === [] || count($items) > 5 || !$this->validDue($due)) {
      return new GoalResult(GoalResult::INVALID);
    }
    $blocked = $this->creationBlock($account, NULL);
    if ($blocked !== NULL) {
      return new GoalResult($blocked);
    }
    $goal = Goal::create([
      'uid' => $account->id(),
      'sector' => $sector,
      'origin' => Goal::ORIGIN_OWN,
      'title' => $title,
      'instructions' => $instructions,
      'steps' => $items,
      'status' => Goal::STATUS_PLANNED,
      'due' => $due ?? '',
    ]);
    return $this->store($goal);
  }

  /**
   * Changes status. Done records the clock time. This does not touch scores.
   */
  public function setStatus(Goal $goal, string $status, ?string $due): GoalResult {
    if (!isset(Goal::statusLabels()[$status]) || !$this->validDue($due)) {
      return new GoalResult(GoalResult::INVALID, $goal);
    }
    $goal->set('status', $status);
    $goal->set('due', $due ?? '');
    if ($status === Goal::STATUS_DONE) {
      if ($goal->getCompletedAt() === NULL) {
        $goal->set('completed_at', $this->clock->now());
      }
    }
    else {
      $goal->set('completed_at', NULL);
    }
    return $this->store($goal);
  }

  /**
   * Marks steps done or open. Newly done steps take the clock time.
   *
   * @param \Drupal\lifebalance_plans\Entity\Goal $goal
   *   Goal to update.
   * @param list<bool> $done
   *   One flag per stored step, in order.
   */
  public function setSteps(Goal $goal, array $done): GoalResult {
    $current = $goal->getSteps();
    if (count($done) !== count($current)) {
      return new GoalResult(GoalResult::INVALID, $goal);
    }
    $items = [];
    foreach ($current as $index => $step) {
      $mark = $done[$index];
      if ($mark && $step->isDone()) {
        $at = $step->getDoneAt() ?? $this->clock->now();
      }
      elseif ($mark) {
        $at = $this->clock->now();
      }
      else {
        $at = NULL;
      }
      $items[] = [
        'text' => $step->getText(),
        'done' => $mark ? 1 : 0,
        'done_at' => $at,
      ];
    }
    $goal->set('steps', $items);
    return $this->store($goal);
  }

  /**
   * Catalog keys already used by this person's active goals.
   *
   * @return list<string>
   *   Recommendation keys to leave out of the engine.
   */
  public function activeRecommendationKeys(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    $keys = [];
    foreach ($this->goals->loadForOwner($uid) as $goal) {
      if (!$goal->isActive()) {
        continue;
      }
      $id = $goal->getRecommendationId();
      if ($id === NULL) {
        continue;
      }
      $recommendation = $storage->load($id);
      if ($recommendation instanceof Recommendation) {
        $keys[] = $recommendation->getCatalogKey();
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Goals for one plan tab.
   *
   * 30 and 90 days include active goals with no due date, plus active goals
   * whose due date falls in that window.
   *
   * @return list<\Drupal\lifebalance_plans\Entity\Goal>
   *   Goals in display order.
   */
  public function forView(AccountInterface $account, string $view): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $today = $this->day(0);
    $end = $view === 'days30' ? $this->day(30) : $this->day(90);
    $matched = [];
    foreach ($this->goals->loadForOwner($uid) as $goal) {
      if ($view === 'closed') {
        if (!$goal->isActive()) {
          $matched[] = $goal;
        }
        continue;
      }
      if (!$goal->isActive()) {
        continue;
      }
      if ($view === 'active') {
        $matched[] = $goal;
        continue;
      }
      $due = $goal->getDue();
      if ($due === NULL || ($due >= $today && $due <= $end)) {
        $matched[] = $goal;
      }
    }
    if ($view === 'days30' || $view === 'days90') {
      usort($matched, static function (Goal $left, Goal $right): int {
        $left_due = $left->getDue() ?? '9999-99-99';
        $right_due = $right->getDue() ?? '9999-99-99';
        return [$left_due, -$left->getCreatedTime()] <=> [$right_due, -$right->getCreatedTime()];
      });
    }
    return $matched;
  }

  /**
   * Configured active-goal limit, never above 10.
   */
  public function limit(): int {
    $value = $this->configFactory->get('lifebalance_plans.settings')->get('active_goal_limit');
    $limit = is_numeric($value) ? (int) $value : self::MAX_LIMIT;
    if ($limit < 1) {
      return 1;
    }
    return min($limit, self::MAX_LIMIT);
  }

  /**
   * Rejects a new goal that the account may not add.
   *
   * Called again from presave so a direct entity save follows the same rule.
   */
  public function creationBlock(AccountInterface $account, ?int $recommendation_id): ?string {
    $uid = (int) $account->id();
    $allowed = $this->entitlementChecker->hasFeatureAccess($account, 'goals');
    if ($uid < 1 || !$allowed) {
      return GoalResult::PREMIUM;
    }
    if ($this->goals->countActive($uid) >= $this->limit()) {
      return GoalResult::LIMIT;
    }
    if ($recommendation_id !== NULL && $this->goals->countActiveForRecommendation($uid, $recommendation_id) > 0) {
      return GoalResult::DUPLICATE;
    }
    return NULL;
  }

  /**
   * Last gate before a new goal is stored.
   */
  public function assertCreatable(Goal $goal): void {
    if (!$goal->isNew()) {
      if ($goal->isActive()) {
        $original = $goal->getOriginal();
        $was_active = $original instanceof Goal && $original->isActive();
        if (!$was_active && $this->goals->countActive((int) $goal->getOwnerId()) >= $this->limit()) {
          throw new EntityStorageException('The active goal limit has been reached.');
        }
      }
      return;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($goal->getOwnerId());
    if (!$account instanceof AccountInterface) {
      throw new EntityStorageException('A goal requires an owner.');
    }
    $code = $this->creationBlock($account, $goal->getRecommendationId());
    if ($code !== NULL) {
      throw new EntityStorageException('This goal cannot be created.');
    }
    if ($goal->getOrigin() === Goal::ORIGIN_RECOMMENDATION) {
      $this->assertRecommendationCopy($goal);
    }
  }

  /**
   * Premium, limit, or an existing active copy.
   */
  private function store(Goal $goal): GoalResult {
    try {
      $goal->save();
    }
    catch (EntityStorageException) {
      return new GoalResult(GoalResult::INVALID, $goal->id() ? $goal : NULL);
    }
    return new GoalResult(GoalResult::SAVED, $goal);
  }

  /**
   * The published recommendation this new goal claims to copy.
   */
  private function assertRecommendationCopy(Goal $goal): void {
    $id = $goal->getRecommendationId();
    $revision_id = $goal->getRecommendationRevisionId();
    if ($id === NULL || $revision_id === NULL) {
      throw new EntityStorageException('A recommendation goal needs its source revision.');
    }
    $recommendation = $this->entityTypeManager->getStorage('lifebalance_recommendation')->load($id);
    if (!$recommendation instanceof Recommendation) {
      throw new EntityStorageException('The source recommendation is missing.');
    }
    if ($recommendation->getStatus() !== Recommendation::STATUS_PUBLISHED) {
      throw new EntityStorageException('Only a published recommendation can become a goal.');
    }
    if ($recommendation->getSafety() === Recommendation::SAFETY_SENSITIVE) {
      throw new EntityStorageException('A sensitive recommendation cannot become a goal.');
    }
    if ((int) $recommendation->getRevisionId() !== $revision_id) {
      throw new EntityStorageException('The copied recommendation revision is required.');
    }
  }

  /**
   * Published recommendation with this catalog key, if there is one.
   */
  private function recommendation(string $key): ?Recommendation {
    if ($key === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $key)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $entity = $storage->load($id);
    return $entity instanceof Recommendation ? $entity : NULL;
  }

  /**
   * Whether this sector is currently active.
   */
  private function activeSector(string $sector): bool {
    $entity = $this->sectors->loadActive()[$sector] ?? NULL;
    return $entity instanceof Sector;
  }

  /**
   * NULL and Y-m-d are the only accepted terms.
   */
  private function validDue(?string $due): bool {
    if ($due === NULL || $due === '') {
      return TRUE;
    }
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $due, new \DateTimeZone('UTC'));
    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $due;
  }

  /**
   * UTC calendar day offset from now.
   */
  private function day(int $offset): string {
    $date = (new \DateTimeImmutable('@' . $this->clock->now()))->setTimezone(new \DateTimeZone('UTC'));
    if ($offset !== 0) {
      $date = $date->modify('+' . $offset . ' days');
    }
    return $date->format('Y-m-d');
  }

}
