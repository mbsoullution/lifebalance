<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementLog;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\lifebalance\Service\TrialResult;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A self-serve trial is one row, one record, and it ends with the data kept.
 */
#[Group('lifebalance')]
final class TrialKernelTest extends KernelTestBase {

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
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('lifebalance.settings')->set('trial_enabled', TRUE)->save();
  }

  /**
   * Two starts leave one trial. Revoke does not open another.
   */
  public function testSecondStartAndRevokeStayUsed(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $note = 'Секретна нотатка trial 77101';
    $this->storeSnapshot($owner, $note);
    $starts = 1_700_000_000;
    $this->container->get(Clock::class)->set($starts);
    $manager = $this->container->get(EntitlementManager::class);
    $first = $manager->startTrial($owner);
    $this->assertSame(TrialResult::SAVED, $first->code);
    $second = $manager->startTrial($owner);
    $this->assertSame(TrialResult::USED, $second->code);
    $this->assertSame(1, $this->usageCount((int) $owner->id()));
    $this->assertSame(1, $this->entitlementCount((int) $owner->id()));
    $entitlement = $first->entitlement;
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $manager->revoke($entitlement, 'Скасування ознайомлення', $owner);
    $again = $manager->startTrial($owner);
    $this->assertSame(TrialResult::USED, $again->code);
    $this->assertSame(1, $this->usageCount((int) $owner->id()));
    $this->assertSame(1, $this->entitlementCount((int) $owner->id()));
    $this->assertFalse($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
    $stored = Snapshot::load($this->snapshotId((int) $owner->id()));
    $this->assertInstanceOf(Snapshot::class, $stored);
    $this->assertSame($note, $stored->getNote());
  }

  /**
   * After the seventh Kyiv day the person is Free and the note remains.
   */
  public function testTrialEndsWithoutTouchingTheSnapshot(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $note = 'Секретна нотатка trial 77102';
    $this->storeSnapshot($owner, $note);
    $starts = 1_700_000_000;
    $clock = $this->container->get(Clock::class);
    $clock->set($starts);
    $manager = $this->container->get(EntitlementManager::class);
    $started = $manager->startTrial($owner);
    $this->assertSame(TrialResult::SAVED, $started->code);
    $entitlement = $started->entitlement;
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $ends = $manager->endOfDays($starts, EntitlementManager::TRIAL_DAYS);
    $this->assertSame($ends, $entitlement->getEnds());
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $clock->set($ends - 1);
    $this->assertTrue($checker->hasPremium($owner));
    $clock->set($ends);
    $this->assertFalse($checker->hasPremium($owner));
    $stored = Snapshot::load($this->snapshotId((int) $owner->id()));
    $this->assertInstanceOf(Snapshot::class, $stored);
    $this->assertSame($note, $stored->getNote());
    $actions = array_column(
      $this->container->get(EntitlementLog::class)->forEntitlement((int) $entitlement->id()),
      'action',
    );
    $this->assertContains(EntitlementLog::TRIAL_START, $actions);
  }

  /**
   * The last-day letter skips a muted person and never quotes the note.
   */
  public function testReminderSkipsMutedPeople(): void {
    $owner = $this->createUser();
    $quiet = $this->createUser();
    $note = 'Секретна нотатка trial 77103';
    $starts = 1_700_000_000;
    $clock = $this->container->get(Clock::class);
    $clock->set($starts);
    $this->setCurrentUser($owner);
    $this->storeSnapshot($owner, $note);
    $manager = $this->container->get(EntitlementManager::class);
    $started = $manager->startTrial($owner);
    $this->assertSame(TrialResult::SAVED, $started->code);
    $entitlement = $started->entitlement;
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $this->container->get(EntitlementNotifier::class)->sendTrialStarted($entitlement);
    $body = $this->mailBodies();
    $this->assertCount(1, $body);
    $this->assertStringContainsString('Картка не потрібна.', $body[0]);
    $this->assertStringNotContainsString($note, $body[0]);

    $ends = $entitlement->getEnds();
    $notifier = $this->container->get(EntitlementNotifier::class);
    $clock->set($ends - EntitlementNotifier::TRIAL_NOTICE_WITHIN - 10);
    $notifier->notifyTrialEnding();
    $this->assertCount(1, $this->mailBodies());
    $clock->set($ends - 3600);
    $notifier->notifyTrialEnding();
    $bodies = $this->mailBodies();
    $this->assertCount(2, $bodies);
    $this->assertStringContainsString('Ознайомлення завершується', $bodies[1]);
    $this->assertStringNotContainsString($note, $bodies[1]);
    $notifier->notifyTrialEnding();
    $this->assertCount(2, $this->mailBodies());

    $this->setCurrentUser($quiet);
    $this->storeSnapshot($quiet, $note);
    $clock->set($starts);
    $muted = $manager->startTrial($quiet);
    $this->assertSame(TrialResult::SAVED, $muted->code);
    $this->container->get('user.data')->set('lifebalance', (int) $quiet->id(), 'mute_service_reminders', 1);
    $muted_record = $muted->entitlement;
    $this->assertInstanceOf(Entitlement::class, $muted_record);
    $clock->set($muted_record->getEnds() - 3600);
    $before = count($this->mailBodies());
    $notifier->notifyTrialEnding();
    $this->assertCount($before, $this->mailBodies());
    $notifier->notifyTrialEnding();
    $this->assertCount($before, $this->mailBodies());
  }

  /**
   * Stores eight scores and a private note.
   */
  private function storeSnapshot(UserInterface $owner, string $note): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 4,
      ];
    }
    Snapshot::create([
      'uid' => $owner->id(),
      'note' => $note,
      'scores' => $scores,
    ])->save();
  }

  /**
   * Usage rows for one account.
   */
  private function usageCount(int $uid): int {
    $value = $this->container->get('database')->select('lifebalance_trial_usage', 't')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ?->fetchField();
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Entitlement rows for one account.
   */
  private function entitlementCount(int $uid): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    return count($storage->getQuery()->accessCheck(FALSE)->condition('uid', $uid)->execute());
  }

  /**
   * The snapshot id for this owner.
   */
  private function snapshotId(int $uid): int {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('uid', $uid)->execute();
    $this->assertCount(1, $ids);
    return (int) reset($ids);
  }

  /**
   * Collected letter bodies.
   *
   * @return list<string>
   *   Bodies in send order.
   *
   * @phpstan-impure
   */
  private function mailBodies(): array {
    $captured = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertIsArray($captured);
    $bodies = [];
    foreach ($captured as $mail) {
      if (!is_array($mail)) {
        continue;
      }
      $body = $mail['body'] ?? '';
      $bodies[] = is_array($body) ? implode("\n", $body) : (string) $body;
    }
    return $bodies;
  }

}
