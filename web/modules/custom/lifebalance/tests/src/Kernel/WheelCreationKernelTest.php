<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The wheel mark is stored once, and the update fills it for existing people.
 */
#[Group('lifebalance')]
final class WheelCreationKernelTest extends KernelTestBase {

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
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['lifebalance']);
  }

  /**
   * A second call keeps the first timestamp and writes no draft.
   */
  public function testEnsureStoresTheTimeOnce(): void {
    $owner = $this->createUser();
    $clock = $this->container->get(Clock::class);
    $creation = $this->container->get(WheelCreation::class);
    $this->assertNull($creation->createdAt($owner));

    $clock->set(1_700_000_000);
    $first = $creation->ensure($owner);
    $clock->set(1_700_000_500);
    $second = $creation->ensure($owner);

    $this->assertSame(1_700_000_000, $first);
    $this->assertSame($first, $second);
    $this->assertSame($first, $creation->createdAt($owner));
    $draft = $this->container->get('user.data')->get('lifebalance', (int) $owner->id(), 'draft');
    $this->assertNull($draft);
  }

  /**
   * Existing snapshots and drafts receive a mark without replacing one.
   */
  public function testUpdateBackfillsExistingWheels(): void {
    $with_snapshot = $this->createUser();
    $snapshot = $this->saveSnapshot($with_snapshot);
    $created = $snapshot->getCreatedTime();

    $draft_only = $this->createUser();
    $this->container->get('user.data')->set('lifebalance', (int) $draft_only->id(), 'draft', [
      'scores' => [],
      'answers' => [],
    ]);

    $already = $this->createUser();
    $this->container->get('user.data')->set('lifebalance', (int) $already->id(), 'wheel_created', 111);
    $this->container->get('user.data')->set('lifebalance', (int) $already->id(), 'draft', [
      'scores' => [],
      'answers' => [],
    ]);

    $clock = $this->container->get(Clock::class);
    $clock->set(1_700_000_900);
    $this->container->get('module_handler')->loadInclude('lifebalance', 'install');
    \lifebalance_update_11006();

    $creation = $this->container->get(WheelCreation::class);
    $this->assertSame($created, $creation->createdAt($with_snapshot));
    $this->assertSame(1_700_000_900, $creation->createdAt($draft_only));
    $this->assertSame(111, $creation->createdAt($already));
  }

  /**
   * Saves one snapshot with a score in every active sector.
   */
  private function saveSnapshot(UserInterface $owner): Snapshot {
    $this->setCurrentUser($owner);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 0,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'scores' => $scores,
      'answers' => [],
    ]);
    $snapshot->save();
    $stored = Snapshot::load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $stored);
    return $stored;
  }

}
