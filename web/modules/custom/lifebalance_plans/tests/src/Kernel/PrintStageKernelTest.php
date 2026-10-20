<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Service\AdminUserStats;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Drupal\lifebalance_plans\Service\ReassessmentPrompt;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The reassessment clock and stage-3 counts stay free of goal text.
 */
#[Group('lifebalance_plans')]
final class PrintStageKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_goal');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['filter', 'views', 'lifebalance', 'lifebalance_plans']);
  }

  /**
   * A goal saved today does not invite a new score.
   */
  public function testBannerIsQuietForNewGoal(): void {
    $owner = $this->createUser();
    $this->grantEntitlement($owner);
    $this->setCurrentUser($owner);
    $manager = $this->container->get(GoalManager::class);
    $saved = $manager->createOwn($owner, 'health', 'Свіжа ціль', 'Опис', ['Крок'], NULL);
    $this->assertSame(GoalResult::SAVED, $saved->code);
    $prompt = $this->container->get(ReassessmentPrompt::class);
    $this->assertSame([], $prompt->forAccount($owner));
  }

  /**
   * Seven days, then thirty. A newer goal does not restart the clock.
   */
  public function testBannerUsesTheEarliestGoal(): void {
    $owner = $this->createUser();
    $this->grantEntitlement($owner);
    $this->setCurrentUser($owner);
    $manager = $this->container->get(GoalManager::class);
    $first = $manager->createOwn($owner, 'health', 'Перша ціль', 'Опис', ['Крок'], NULL);
    $this->assertSame(GoalResult::SAVED, $first->code);
    $goal = $first->goal;
    $this->assertInstanceOf(Goal::class, $goal);
    $prompt = $this->container->get(ReassessmentPrompt::class);
    $now = $this->container->get(Clock::class)->now();
    $goal->set('created', $now - (8 * 86400));
    $goal->save();
    $week = $prompt->forAccount($owner);
    $this->assertCount(1, $week);
    $this->assertSame('health', $week[0]['sector_id']);
    $this->assertSame(7, $week[0]['days']);

    $goal->set('created', $now - (31 * 86400));
    $goal->save();
    $later = $manager->createOwn($owner, 'health', 'Новіша ціль', 'Опис', ['Крок'], NULL);
    $this->assertSame(GoalResult::SAVED, $later->code);
    $month = $prompt->forAccount($owner);
    $this->assertCount(1, $month);
    $this->assertSame(30, $month[0]['days']);
  }

  /**
   * Counts people and done goals. The title never leaves the goal table.
   */
  public function testStatisticsOmitGoalText(): void {
    $first = $this->createUser();
    $second = $this->createUser();
    $this->grantEntitlement($first);
    $this->grantEntitlement($second);
    $manager = $this->container->get(GoalManager::class);
    $title = 'Секретна ціль 55210';
    $this->setCurrentUser($first);
    $done = $manager->createOwn($first, 'health', $title, 'Прихований опис', ['Крок'], NULL);
    $this->assertSame(GoalResult::SAVED, $done->code);
    $goal = $done->goal;
    $this->assertInstanceOf(Goal::class, $goal);
    $saved = $manager->setStatus($goal, Goal::STATUS_DONE, NULL);
    $this->assertSame(GoalResult::SAVED, $saved->code);
    $this->setCurrentUser($second);
    $open = $manager->createOwn($second, 'finances', 'Інша ціль', 'Опис', ['Крок'], NULL);
    $this->assertSame(GoalResult::SAVED, $open->code);

    $stats = $this->container->get(AdminUserStats::class);
    $totals = $stats->statistics(0);
    $this->assertSame(2, $totals['with_goals']);
    $this->assertSame(1, $totals['goals_done']);
    $encoded = json_encode($totals);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString($title, $encoded);
    $this->assertStringNotContainsString('Прихований опис', $encoded);
  }

}
