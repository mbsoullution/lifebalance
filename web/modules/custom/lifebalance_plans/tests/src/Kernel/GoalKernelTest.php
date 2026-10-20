<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\PersonalExport;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Goals keep a copy, ignore later edits, and stay off the snapshot.
 */
#[Group('lifebalance_plans')]
final class GoalKernelTest extends KernelTestBase {

  use EntitlementTestTrait;
  use UserCreationTrait;

  /**
   * Active sector IDs.
   *
   * @var list<string>
   */
  private const SECTORS = [
    'health',
    'relationships',
    'environment',
    'purpose',
    'finances',
    'self_development',
    'life_brightness',
    'spirituality',
  ];

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
    $this->installConfig(['filter', 'views', 'lifebalance', 'lifebalance_plans']);
    $editor = $this->createUser();
    $this->setCurrentUser($editor);
    Criterion::create([
      'key' => 'health.f01',
      'sector' => 'health',
      'tier' => Criterion::TIER_FREE,
      'text' => 'Твердження для цілі',
      'weight' => 1,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $editor->id(),
    ])->save();
  }

  /**
   * A new recommendation revision does not rewrite the goal.
   */
  public function testCopySurvivesRecommendationRevision(): void {
    $owner = $this->createUser();
    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $this->grantEntitlement($owner);
    $recommendation = $this->publish(
      $publisher,
      'health.copy.one',
      'Початкова назва',
      'Початковий опис цілі.',
      ['Початковий крок'],
      Recommendation::SAFETY_NONE,
      Recommendation::TIER_PREMIUM,
    );
    $revision_id = (int) $recommendation->getRevisionId();

    $this->setCurrentUser($owner);
    $result = $this->manager()->fromRecommendation($owner, 'health.copy.one');
    $this->assertSame(GoalResult::SAVED, $result->code);
    $goal = $result->goal;
    $this->assertInstanceOf(Goal::class, $goal);
    $this->assertSame('Початкова назва', $goal->getTitle());
    $this->assertSame('Початковий опис цілі.', $goal->getInstructions());
    $this->assertSame($revision_id, $goal->getRecommendationRevisionId());

    $this->setCurrentUser($publisher);
    $recommendation = $this->reloadRecommendation($recommendation);
    $recommendation->set('title', 'Нова назва');
    $recommendation->set('body', [
      'value' => 'Новий опис, якого в цілі немає.',
      'format' => Recommendation::BODY_FORMAT,
    ]);
    $recommendation->save();
    $this->assertNotSame($revision_id, (int) $recommendation->getRevisionId());

    $this->setCurrentUser($owner);
    $stored = $this->reloadGoal($goal);
    $this->assertSame('Початкова назва', $stored->getTitle());
    $this->assertSame('Початковий опис цілі.', $stored->getInstructions());
    $this->assertSame('Початковий крок', $stored->getSteps()[0]->getText());
    $this->assertSame($revision_id, $stored->getRecommendationRevisionId());
    $this->assertSame(GoalResult::DUPLICATE, $this->manager()->fromRecommendation($owner, 'health.copy.one')->code);
  }

  /**
   * Marking a goal done leaves the snapshot scores and answers alone.
   */
  public function testCompletionDoesNotChangeSnapshot(): void {
    $owner = $this->createUser();
    $publisher = $this->createUser([
      'publish lifebalance recommendations',
      'edit lifebalance recommendations',
    ]);
    $criterion = $this->criterion();
    $this->setCurrentUser($owner);
    $snapshot = $this->snapshot($owner, $criterion);
    $before = $this->signature($snapshot);

    $this->publish($publisher, 'health.goal.snap', 'Назва для зрізу', 'Опис для зрізу.', ['Крок зрізу'], Recommendation::SAFETY_NONE, Recommendation::TIER_PREMIUM);
    $this->grantEntitlement($owner);
    $this->setCurrentUser($owner);
    $result = $this->manager()->fromRecommendation($owner, 'health.goal.snap');
    $goal = $result->goal;
    $this->assertInstanceOf(Goal::class, $goal);
    $updated = $this->manager()->setStatus($goal, Goal::STATUS_DONE, NULL);
    $this->assertSame(GoalResult::SAVED, $updated->code);
    $this->assertInstanceOf(Goal::class, $updated->goal);
    $steps = $this->manager()->setSteps($updated->goal, [TRUE]);
    $this->assertSame(GoalResult::SAVED, $steps->code);
    $this->assertInstanceOf(Goal::class, $steps->goal);
    $this->assertTrue($steps->goal->getSteps()[0]->isDone());
    $this->assertNotNull($steps->goal->getCompletedAt());

    $reloaded = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot')->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $reloaded);
    $this->assertSame($before, $this->signature($reloaded));
  }

  /**
   * Premium recommendations and the active limit are enforced on create.
   */
  public function testPremiumIsRequiredAndLimitIsTen(): void {
    $owner = $this->createUser();
    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $this->publish($publisher, 'health.goal.premium', 'Платна підказка', 'Опис платної підказки.', ['Крок'], Recommendation::SAFETY_NONE, Recommendation::TIER_PREMIUM);
    $this->publish($publisher, 'health.goal.sensitive', 'Чутлива порада', 'Цього тексту не має бути в цілі.', ['Крок'], Recommendation::SAFETY_SENSITIVE, Recommendation::TIER_PREMIUM);

    $this->setCurrentUser($owner);
    $this->assertSame(GoalResult::PREMIUM, $this->manager()->fromRecommendation($owner, 'health.goal.premium')->code);
    $this->assertSame(0, $this->goalCount());

    $this->grantEntitlement($owner);
    $this->setCurrentUser($owner);
    $this->assertSame(GoalResult::SENSITIVE, $this->manager()->fromRecommendation($owner, 'health.goal.sensitive')->code);
    $saved = $this->manager()->fromRecommendation($owner, 'health.goal.premium');
    $this->assertSame(GoalResult::SAVED, $saved->code);

    for ($index = 0; $index < 9; $index++) {
      $own = $this->manager()->createOwn($owner, 'health', 'Ціль ' . $index, 'Опис власної цілі.', ['Крок ' . $index], NULL);
      $this->assertSame(GoalResult::SAVED, $own->code);
    }
    $blocked = $this->manager()->createOwn($owner, 'health', 'Зайва ціль', 'Опис зайвої цілі.', ['Крок'], NULL);
    $this->assertSame(GoalResult::LIMIT, $blocked->code);
    $this->assertSame(10, $this->goalCount());

    $this->revokeEntitlements($owner);
    $this->setCurrentUser($owner);
    $this->assertSame(GoalResult::PREMIUM, $this->manager()->fromRecommendation($owner, 'health.goal.premium')->code);
    $goal = $saved->goal;
    $this->assertInstanceOf(Goal::class, $goal);
    $status = $this->manager()->setStatus($this->reloadGoal($goal), Goal::STATUS_IN_PROGRESS, '2030-01-15');
    $this->assertSame(GoalResult::SAVED, $status->code);
    $this->assertInstanceOf(Goal::class, $status->goal);
    $this->assertSame(Goal::STATUS_IN_PROGRESS, $status->goal->getStatus());
    $this->assertSame('2030-01-15', $status->goal->getDue());
  }

  /**
   * Export keeps the copy. Account removal deletes only that person's goals.
   */
  public function testExportAndPredelete(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $this->grantEntitlement($owner);
    $this->grantEntitlement($other);
    $this->setCurrentUser($owner);
    $owned = $this->manager()->createOwn($owner, 'health', 'Ціль експорту', 'Опис експорту.', ['Крок експорту'], NULL);
    $this->assertInstanceOf(Goal::class, $owned->goal);
    $this->setCurrentUser($other);
    $foreign = $this->manager()->createOwn($other, 'health', 'Чужа ціль', 'Чужий опис.', ['Чужий крок'], NULL);
    $this->assertInstanceOf(Goal::class, $foreign->goal);

    $this->setCurrentUser($owner);
    $export = $this->container->get(PersonalExport::class);
    $document = $export->document($owner);
    $this->assertCount(1, $document['goals']);
    $goal = $document['goals'][0];
    $this->assertSame('Ціль експорту', $goal['title']);
    $this->assertSame('Опис експорту.', $goal['instructions']);
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('Чужа ціль', $encoded);
    $csv = $export->csv($owner);
    $this->assertStringContainsString('Ціль експорту', $csv);
    $this->assertStringContainsString('Крок експорту', $csv);
    $this->assertStringNotContainsString('Чужа ціль', $csv);

    $owner->delete();
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal');
    $storage->resetCache();
    $this->assertNull($storage->load($owned->goal->id()));
    $this->assertInstanceOf(Goal::class, $storage->load($foreign->goal->id()));
  }

  /**
   * Goal manager.
   */
  private function manager(): GoalManager {
    return $this->container->get(GoalManager::class);
  }

  /**
   * How many goals exist.
   */
  private function goalCount(): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal');
    return (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Stores one published recommendation.
   *
   * @param \Drupal\user\UserInterface $publisher
   *   Editor who may publish.
   * @param string $key
   *   Catalog key.
   * @param string $title
   *   Title copied into a goal.
   * @param string $body
   *   Instructions copied into a goal.
   * @param list<string> $steps
   *   Checklist steps.
   * @param string $safety
   *   Safety value.
   * @param string $tier
   *   Tier value.
   */
  private function publish(
    UserInterface $publisher,
    string $key,
    string $title,
    string $body,
    array $steps,
    string $safety,
    string $tier,
  ): Recommendation {
    $this->setCurrentUser($publisher);
    $recommendation = Recommendation::create([
      'key' => $key,
      'sector' => 'health',
      'trigger_criteria' => ['health.f01'],
      'trigger_answers' => ['no', 'partial'],
      'title' => $title,
      'body' => [
        'value' => $body,
        'format' => Recommendation::BODY_FORMAT,
      ],
      'steps' => $steps,
      'type' => Recommendation::TYPE_REFLECTION,
      'effort' => Recommendation::EFFORT_SMALL,
      'safety' => $safety,
      'tier' => $tier,
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
    foreach (self::SECTORS as $sector) {
      $scores[] = ['sector_id' => $sector, 'value' => 4];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Нотатка до цілі',
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
   * Scores, answers, and the note, for a before/after comparison.
   *
   * @return array<string, mixed>
   *   Comparable snapshot data.
   */
  private function signature(Snapshot $snapshot): array {
    $scores = [];
    foreach ($snapshot->getScores() as $item) {
      $scores[] = [$item->getSectorId(), $item->getScore(), $item->getGuide(), $item->getAnswered()];
    }
    $answers = [];
    foreach ($snapshot->getAnswers() as $item) {
      $answers[] = [$item->getCriterionId(), $item->getCriterionRevisionId(), $item->getAnswer()];
    }
    return [
      'note' => $snapshot->getNote(),
      'scores' => $scores,
      'answers' => $answers,
    ];
  }

  /**
   * Reloads a goal from storage.
   */
  private function reloadGoal(Goal $goal): Goal {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal');
    $storage->resetCache([$goal->id()]);
    $loaded = $storage->load($goal->id());
    $this->assertInstanceOf(Goal::class, $loaded);
    return $loaded;
  }

  /**
   * Reloads a recommendation from storage.
   */
  private function reloadRecommendation(Recommendation $recommendation): Recommendation {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_recommendation');
    $storage->resetCache([$recommendation->id()]);
    $loaded = $storage->load($recommendation->id());
    $this->assertInstanceOf(Recommendation::class, $loaded);
    return $loaded;
  }

}
