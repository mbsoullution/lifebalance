<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\AdminUserStats;
use Drupal\lifebalance\Service\FunnelStats;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin aggregates never include scores, answers, or notes.
 */
#[Group('lifebalance')]
final class AdminUserStatsKernelTest extends KernelTestBase {

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
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installConfig(['lifebalance']);
    $this->createUser();
  }

  /**
   * The wheel tab and the export skip the secret note and draft value.
   */
  public function testAggregatesSkipPersonalText(): void {
    $scored = $this->createUser([], 'scoredwheel');
    $started = $this->createUser([], 'startedwheel');
    $draft_only = $this->createUser([], 'draftonly');
    $quiet = $this->createUser([], 'quietwheel');
    $this->setCurrentUser($scored);
    $this->saveSnapshot($scored, 'Секретна нотатка 84721');
    $this->userData()->set('lifebalance', (int) $scored->id(), 'wheel_created', 1700000001);
    $this->userData()->set('lifebalance', (int) $started->id(), 'wheel_created', 1700000002);
    $this->userData()->set('lifebalance', (int) $started->id(), 'draft', [
      'note' => 'Секретна чернетка 84721',
      'scores' => ['health' => 9],
    ]);
    $this->userData()->set('lifebalance', (int) $draft_only->id(), 'draft', [
      'note' => 'Секретна чернетка 84721',
      'scores' => ['health' => 8],
    ]);

    $stats = $this->stats();
    $nowheel = $stats->all('nowheel', '', 'name', 'asc', FALSE);
    $names = array_column($nowheel, 'name');
    $this->assertContains('quietwheel', $names);
    $this->assertContains('draftonly', $names);
    $this->assertNotContains('scoredwheel', $names);
    $this->assertNotContains('startedwheel', $names);
    $with_wheel = array_column($stats->all('wheel', '', 'name', 'asc', FALSE), 'name');
    $this->assertContains('scoredwheel', $with_wheel);
    $this->assertContains('startedwheel', $with_wheel);
    $this->assertNotContains('quietwheel', $with_wheel);
    $this->assertNotContains('draftonly', $with_wheel);

    $all = $stats->all('all', '', 'name', 'asc', FALSE);
    $encoded = json_encode($all);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('Секретна нотатка 84721', $encoded);
    $this->assertStringNotContainsString('Секретна чернетка 84721', $encoded);
    $this->assertStringNotContainsString('quietwheel@example.com', $encoded);

    $by_name = [];
    foreach ($all as $row) {
      $by_name[$row['name']] = $row;
    }
    $this->assertSame('snapshot', $by_name['scoredwheel']['state']);
    $this->assertSame(1, $by_name['scoredwheel']['snapshots']);
    $this->assertSame('started', $by_name['startedwheel']['state']);
    $this->assertSame(0, $by_name['startedwheel']['snapshots']);
    $this->assertSame('registered', $by_name['draftonly']['state']);
    $this->assertSame('registered', $by_name['quietwheel']['state']);
    $this->assertArrayNotHasKey('mail', $by_name['quietwheel']);

    $with_mail = $stats->one((int) $quiet->id(), TRUE);
    $this->assertIsArray($with_mail);
    $this->assertSame('quietwheel@example.com', $with_mail['mail']);

    $csv = $stats->csv($stats->all('all', 'quietwheel', 'name', 'asc', FALSE), FALSE);
    $this->assertStringContainsString('quietwheel', $csv);
    $this->assertStringNotContainsString('quietwheel@example.com', $csv);
    $this->assertStringNotContainsString('Секретна нотатка 84721', $csv);
    $this->assertStringContainsString('Зареєстрований', $csv);

    $totals = $stats->statistics(0);
    $this->assertGreaterThanOrEqual(4, $totals['accounts']);
    $this->assertGreaterThanOrEqual(2, $totals['started']);
    $this->assertGreaterThanOrEqual(1, $totals['with_snapshot']);
    $this->assertGreaterThanOrEqual(1, $totals['snapshots']);
    $this->assertSame(0, $totals['premium']);
    $this->assertSame(0, $totals['ending']);
    $this->assertSame(0, $totals['expired']);
  }

  /**
   * Funnel steps are counts. A note never leaves the snapshot table.
   */
  public function testFunnelCountsEventsWithoutContent(): void {
    $note = 'Секретна нотатка воронки 66201';
    $early = $this->createUser();
    $once = $this->createUser();
    $this->setCurrentUser($early);
    $this->userData()->set('lifebalance', (int) $early->id(), 'wheel_created', 1_700_000_000);
    $start = 1_700_000_000;
    $return_at = $start + (31 * 86400);
    $this->stampSnapshot($this->saveSnapshot($early, $note), $start);
    $this->stampSnapshot($this->saveSnapshot($early, $note), $return_at);
    $this->setCurrentUser($once);
    $this->saveSnapshot($once, $note);
    $this->container->get('database')->insert('lifebalance_trial_usage')->fields([
      'uid' => (int) $early->id(),
      'used_at' => $start + 100,
      'entitlement_id' => 1,
    ])->execute();

    $funnel = $this->container->get(FunnelStats::class);
    $all = $funnel->counts(0);
    $this->assertGreaterThanOrEqual(1, $all['wheel']);
    $this->assertGreaterThanOrEqual(2, $all['first_snapshot']);
    $this->assertSame(1, $all['trial']);
    $this->assertSame(1, $all['returned']);
    $after = $funnel->counts($return_at + 1);
    $this->assertSame(0, $after['returned']);
    $this->assertSame(0, $after['trial']);
    $encoded = json_encode($all);
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString($note, $encoded);
  }

  /**
   * Plan tabs follow the clock and never include the private note.
   */
  public function testPlanTabs(): void {
    $now = 1_700_000_000;
    $clock = $this->container->get(Clock::class);
    $manager = $this->container->get(EntitlementManager::class);
    $clock->set($now);
    $actor = $this->createUser();
    $premium = $this->createUser([], 'planpremium');
    $ending = $this->createUser([], 'planending');
    $expired = $this->createUser([], 'planexpired');
    $free = $this->createUser([], 'planfree');
    $trial = $this->createUser([], 'plantrial');
    $paid = $this->createUser([], 'planpaid');
    $both = $this->createUser([], 'planboth');
    $free->set('created', 1_600_000_000);
    $free->save();
    $this->setCurrentUser($premium);
    $this->saveSnapshot($premium, 'Секретна нотатка плану');
    $manager->grant($premium, Entitlement::SOURCE_ADMIN, $now, $now + (30 * 86400), 'Довгий', $actor, FALSE);
    $manager->grant($ending, Entitlement::SOURCE_ADMIN, $now, $now + (2 * 86400), 'Короткий', $actor, FALSE);
    $manager->grant($expired, Entitlement::SOURCE_ADMIN, 1_600_000_000, 1_690_000_000, 'Минулий', $actor, FALSE);
    $manager->grant($trial, Entitlement::SOURCE_TRIAL, $now, $now + (8 * 86400), 'Ознайомлення таємне', $actor, FALSE);
    $manager->grantFromOrder($paid, 70, $now + (30 * 86400), $now);
    $manager->grantFromOrder($both, 71, $now + (30 * 86400), $now);
    $manager->grant($both, Entitlement::SOURCE_ADMIN, $now, $now + (10 * 86400), 'Разом таємно', $actor, FALSE);

    $stats = $this->stats();
    $premium_names = $this->names($stats, 'premium');
    $this->assertContains('planpremium', $premium_names);
    $this->assertContains('planending', $premium_names);
    $this->assertNotContains('planfree', $premium_names);
    $this->assertNotContains('planexpired', $premium_names);
    $free_names = $this->names($stats, 'free');
    $this->assertContains($free->getAccountName(), $free_names);
    $this->assertNotContains('planpremium', $free_names);
    $this->assertNotContains('planexpired', $free_names);
    $ending_names = $this->names($stats, 'ending');
    $this->assertContains('planending', $ending_names);
    $this->assertNotContains('planpremium', $ending_names);
    $expired_names = $this->names($stats, 'expired');
    $this->assertContains('planexpired', $expired_names);
    $this->assertNotContains('planpremium', $expired_names);
    $this->assertNotContains('planfree', $expired_names);
    $trial_names = $this->names($stats, 'trial');
    $this->assertContains('plantrial', $trial_names);
    $this->assertNotContains('planpaid', $trial_names);
    $this->assertNotContains('planpremium', $trial_names);
    $paid_names = $this->names($stats, 'paid');
    $this->assertContains('planpaid', $paid_names);
    $this->assertContains('planboth', $paid_names);
    $this->assertNotContains('planpremium', $paid_names);
    $manual_names = $this->names($stats, 'manual');
    $this->assertContains('planpremium', $manual_names);
    $this->assertContains('planending', $manual_names);
    $this->assertContains('planboth', $manual_names);
    $this->assertNotContains('planpaid', $manual_names);
    $this->assertNotContains('plantrial', $manual_names);
    $found = array_column($stats->all('all', (string) $free->id(), 'name', 'asc', FALSE), 'name');
    $this->assertContains('planfree', $found);
    $this->assertNotContains('planpremium', $found);
    $zone = new \DateTimeZone('Europe/Kyiv');
    $day = (new \DateTimeImmutable('@1600000000'))->setTimezone($zone)->format('Y-m-d');
    $by_day = array_column($stats->all('all', '', 'name', 'asc', FALSE, $day, $day), 'name');
    $this->assertContains('planfree', $by_day);
    $this->assertNotContains('planpremium', $by_day);

    $row = $stats->one((int) $premium->id(), FALSE);
    $this->assertIsArray($row);
    $this->assertSame('premium', $row['plan']);
    $this->assertSame(Entitlement::SOURCE_ADMIN, $row['source']);
    $both_row = $stats->one((int) $both->id(), FALSE);
    $this->assertIsArray($both_row);
    $this->assertSame('admin_grant,payment', $both_row['source']);
    $encoded = json_encode($stats->all('all', '', 'name', 'asc', FALSE));
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('Секретна нотатка плану', $encoded);
    $this->assertStringNotContainsString('Довгий', $encoded);
    $this->assertStringNotContainsString('Разом таємно', $encoded);
    $this->assertStringNotContainsString('Ознайомлення таємне', $encoded);

    $totals = $stats->statistics(0);
    $this->assertSame(5, $totals['premium']);
    $this->assertSame(3, $totals['premium_admin']);
    $this->assertSame(2, $totals['premium_payment']);
    $this->assertSame(1, $totals['premium_trial']);
    $this->assertSame(1, $totals['ending']);
    $this->assertSame(1, $totals['expired']);
  }

  /**
   * Names on one tab, in name order.
   *
   * @return list<int|string|null>
   *   Account names.
   */
  private function names(AdminUserStats $stats, string $tab): array {
    return array_column($stats->all($tab, '', 'name', 'asc', FALSE), 'name');
  }

  /**
   * Stores eight sector scores and a private note.
   *
   * @return int
   *   The snapshot id.
   */
  private function saveSnapshot(UserInterface $owner, string $note): int {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 6,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => $note,
      'scores' => $scores,
    ]);
    $snapshot->save();
    return (int) $snapshot->id();
  }

  /**
   * Pins the stored creation time of one snapshot.
   */
  private function stampSnapshot(int $id, int $created): void {
    $this->container->get('database')->update('lifebalance_snapshot')
      ->fields(['created' => $created])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * The admin aggregate service.
   */
  private function stats(): AdminUserStats {
    return $this->container->get(AdminUserStats::class);
  }

  /**
   * User data storage.
   */
  private function userData(): UserDataInterface {
    return $this->container->get('user.data');
  }

}
