<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\PersonalExport;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Drupal\lifebalance_plans\Service\PlanManager;
use Drupal\lifebalance_plans\Service\PlanResult;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A person has one active 30-day plan. Goals keep their copied text.
 */
#[Group('lifebalance_plans')]
final class PlanKernelTest extends KernelTestBase {

  use EntitlementTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'views',
    'lifebalance',
    'lifebalance_plans',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installEntitySchema('lifebalance_recommendation');
    $this->installEntitySchema('lifebalance_goal');
    $this->installEntitySchema('lifebalance_plan');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['filter', 'views', 'user', 'lifebalance', 'lifebalance_plans']);
    $editor = $this->createUser();
    $this->setCurrentUser($editor);
    Criterion::create([
      'key' => 'health.f01',
      'sector' => 'health',
      'tier' => Criterion::TIER_FREE,
      'text' => 'Твердження для плану',
      'weight' => 1,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $editor->id(),
    ])->save();
  }

  /**
   * One active plan, a stable copy, and no second active plan.
   */
  public function testSingleActivePlanKeepsTheCopy(): void {
    $owner = $this->createUser();
    $this->container->get(Clock::class)->set(1700000000);
    $this->grantEntitlement($owner);
    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $recommendation = $this->publish($publisher, 'health.plan.copy', 'Текст плану', 'Опис плану.');
    $this->setCurrentUser($owner);

    $this->assertSame(PlanResult::PREMIUM, $this->plans()->start($this->createUser(), ['health'], ['health.plan.copy'])->code);
    $started = $this->plans()->start($owner, ['health'], ['health.plan.copy']);
    $this->assertSame(PlanResult::SAVED, $started->code);
    $plan = $started->plan;
    $this->assertInstanceOf(Plan::class, $plan);
    $this->assertSame(
      $this->container->get(EntitlementManager::class)->endOfDays(1700000000, 30),
      $plan->getEnds(),
    );
    $goal = $this->plans()->goals($plan)[0];
    $this->assertSame((int) $plan->id(), $goal->getPlanId());
    $this->assertSame('Текст плану', $goal->getTitle());
    $this->assertSame((int) $recommendation->getRevisionId(), $goal->getRecommendationRevisionId());

    $this->setCurrentUser($publisher);
    $recommendation->set('title', 'Новий текст плану');
    $recommendation->setNewRevision(TRUE);
    $recommendation->save();
    $this->setCurrentUser($owner);
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal');
    $storage->resetCache([$goal->id()]);
    $reloaded = $storage->load($goal->id());
    $this->assertInstanceOf(Goal::class, $reloaded);
    $this->assertSame('Текст плану', $reloaded->getTitle());

    $again = $this->plans()->start($owner, ['health'], ['health.plan.copy']);
    $this->assertSame(PlanResult::ACTIVE, $again->code);
    $this->assertSame(1, $this->activeCount((int) $owner->id()));
  }

  /**
   * Completing a goal or the plan leaves the snapshot unchanged.
   */
  public function testCompletionLeavesTheSnapshotAndInvites(): void {
    $owner = $this->createUser();
    $this->container->get(Clock::class)->set(1700000000);
    $this->grantEntitlement($owner);
    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $this->publish($publisher, 'health.plan.done', 'Ціль плану', 'Опис цілі плану.');
    $criterion = $this->criterion();
    $this->setCurrentUser($owner);
    $snapshot = $this->snapshot($owner, $criterion);
    $before = $this->signature($snapshot);
    $this->container->get(Clock::class)->set(1700000000);
    $started = $this->plans()->start($owner, ['health'], ['health.plan.done']);
    $this->assertInstanceOf(Plan::class, $started->plan);
    $goal = $this->plans()->goals($started->plan)[0];
    $updated = $this->container->get(GoalManager::class)->setStatus($goal, Goal::STATUS_DONE, NULL);
    $this->assertSame(GoalResult::SAVED, $updated->code);
    $this->assertSame($before, $this->signature($this->reloadSnapshot($snapshot)));

    $this->container->get(Clock::class)->set($started->plan->getEnds() - 1);
    $this->assertSame(0, $this->plans()->completeDue());
    $this->container->get(Clock::class)->set($started->plan->getEnds());
    $this->assertSame(1, $this->plans()->completeDue());
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_plan');
    $storage->resetCache([$started->plan->id()]);
    $closed = $storage->load($started->plan->id());
    $this->assertInstanceOf(Plan::class, $closed);
    $this->assertSame(Plan::STATUS_COMPLETED, $closed->getStatus());
    $this->assertTrue($closed->inviteOpen());
    $this->assertTrue($closed->completionMailed());
    $this->assertTrue($this->plans()->invitationOpen($owner));
    $mails = $this->container->get('state')->get('system.test_mail_collector');
    $this->assertIsArray($mails);
    $this->assertCount(1, $mails);
    $body = $mails[0]['body'];
    $body = is_array($body) ? implode("\n", $body) : (string) $body;
    $this->assertStringContainsString('План на 30 днів завершився.', $body);
    $this->assertStringNotContainsString('Ціль плану', $body);
    $this->assertSame($before, $this->signature($this->reloadSnapshot($snapshot)));

    $this->snapshot($owner, $criterion);
    $this->assertFalse($this->plans()->invitationOpen($owner));

    $this->container->get(Clock::class)->set(1700000000);
    $next = $this->plans()->start($owner, ['health'], ['health.plan.done']);
    $this->assertSame(PlanResult::SAVED, $next->code);
    $this->assertSame(PlanResult::SAVED, $this->plans()->abandon($owner)->code);
    $this->assertNull($this->plans()->active($owner));
    $this->assertCount(2, $this->plans()->archive($owner));
  }

  /**
   * Another person and staff cannot see the plan. Export and deletion stay own.
   */
  public function testAccessExportAndDelete(): void {
    $this->container->get(Clock::class)->set(1700000000);
    $owner = $this->createUser();
    $other = $this->createUser();
    $this->grantEntitlement($owner);
    $this->grantEntitlement($other);
    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $this->publish($publisher, 'health.plan.own', 'Власний план', 'Опис власного плану.');
    $this->publish($publisher, 'health.plan.other', 'Чужий план', 'Опис чужого плану.');
    $this->setCurrentUser($owner);
    $owned = $this->plans()->start($owner, ['health'], ['health.plan.own']);
    $this->assertInstanceOf(Plan::class, $owned->plan);
    $this->setCurrentUser($other);
    $foreign = $this->plans()->start($other, ['health'], ['health.plan.other']);
    $this->assertInstanceOf(Plan::class, $foreign->plan);

    $this->assertFalse($owned->plan->access('view', $other));
    $this->assertFalse($owned->plan->access('update', $other));
    $this->assertFalse($owned->plan->access('delete', $other));
    $root = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $root);
    $this->assertFalse($owned->plan->access('view', $root));
    foreach (['site_manager', 'content_editor', 'support_manager'] as $role) {
      $staff = $this->createUser();
      $staff->addRole($role);
      $staff->save();
      $this->assertFalse($owned->plan->access('view', $staff));
    }

    $this->setCurrentUser($owner);
    $document = $this->container->get(PersonalExport::class)->document($owner);
    $this->assertCount(1, $document['plans']);
    $this->assertSame('active', $document['plans'][0]['status']);
    $this->assertSame(['health'], $document['plans'][0]['focus_sectors']);
    $this->assertSame((int) $owned->plan->id(), $document['goals'][0]['plan_id']);
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('Чужий план', $encoded);

    $owner->delete();
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_plan');
    $storage->resetCache();
    $this->assertNull($storage->load($owned->plan->id()));
    $this->assertInstanceOf(Plan::class, $storage->load($foreign->plan->id()));
  }

  /**
   * Plan manager.
   */
  private function plans(): PlanManager {
    return $this->container->get(PlanManager::class);
  }

  /**
   * Active plans of one uid.
   */
  private function activeCount(int $uid): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_plan');
    return (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('status', Plan::STATUS_ACTIVE)
      ->count()
      ->execute();
  }

  /**
   * Stores one published recommendation.
   */
  private function publish(UserInterface $publisher, string $key, string $title, string $body): Recommendation {
    $this->setCurrentUser($publisher);
    $recommendation = Recommendation::create([
      'key' => $key,
      'sector' => 'health',
      'trigger_criteria' => ['health.f01'],
      'trigger_answers' => ['no'],
      'title' => $title,
      'body' => [
        'value' => $body,
        'format' => Recommendation::BODY_FORMAT,
      ],
      'steps' => ['Один крок плану'],
      'type' => Recommendation::TYPE_REFLECTION,
      'effort' => Recommendation::EFFORT_SMALL,
      'safety' => Recommendation::SAFETY_NONE,
      'tier' => Recommendation::TIER_PREMIUM,
      'status' => Recommendation::STATUS_PUBLISHED,
      'weight' => 1,
      'uid' => $publisher->id(),
    ]);
    $recommendation->save();
    return $recommendation;
  }

  /**
   * The published criterion created during setup.
   */
  private function criterion(): Criterion {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_criterion');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('key', 'health.f01')->execute();
    $loaded = $storage->load(reset($ids));
    $this->assertInstanceOf(Criterion::class, $loaded);
    return $loaded;
  }

  /**
   * A snapshot with one answer and a fixed score on every sector.
   */
  private function snapshot(UserInterface $owner, Criterion $criterion): Snapshot {
    $scores = [];
    foreach ([
      'health',
      'relationships',
      'environment',
      'purpose',
      'finances',
      'self_development',
      'life_brightness',
      'spirituality',
    ] as $sector) {
      $scores[] = ['sector_id' => $sector, 'value' => 4];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Нотатка плану',
      'scores' => $scores,
      'answers' => [
        [
          'criterion_id' => (int) $criterion->id(),
          'criterion_revision_id' => (int) $criterion->getRevisionId(),
          'answer' => 'no',
        ],
      ],
    ]);
    $snapshot->save();
    return $snapshot;
  }

  /**
   * Scores, answers, and the note.
   *
   * @return array<string, mixed>
   *   Comparable snapshot data.
   */
  private function signature(Snapshot $snapshot): array {
    $scores = [];
    foreach ($snapshot->getScores() as $item) {
      $scores[] = [$item->getSectorId(), $item->getScore()];
    }
    return [
      'note' => $snapshot->getNote(),
      'scores' => $scores,
    ];
  }

  /**
   * Reloads a snapshot from storage.
   */
  private function reloadSnapshot(Snapshot $snapshot): Snapshot {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([$snapshot->id()]);
    $loaded = $storage->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $loaded);
    return $loaded;
  }

}
