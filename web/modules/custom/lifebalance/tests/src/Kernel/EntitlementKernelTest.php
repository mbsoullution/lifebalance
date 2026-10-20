<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\EntitlementStorage;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Form\SectorQuestionnaireForm;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EffectiveStatus;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementLog;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Access is a set of timed records, not a role.
 */
#[Group('lifebalance')]
final class EntitlementKernelTest extends KernelTestBase {

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
    'lifebalance',
  ];

  /**
   * The person who receives access.
   */
  private UserInterface $owner;

  /**
   * Another person, used as the actor.
   */
  private UserInterface $actor;

  /**
   * Module clock.
   */
  private Clock $clock;

  /**
   * Access changes.
   */
  private EntitlementManager $manager;

  /**
   * Access checks.
   */
  private EntitlementCheckerInterface $checker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installConfig(['lifebalance']);
    $this->createUser();
    $this->owner = $this->createUser();
    $this->actor = $this->createUser();
    $this->setCurrentUser($this->owner);
    $clock = $this->container->get(Clock::class);
    $manager = $this->container->get(EntitlementManager::class);
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $this->clock = $clock;
    $this->manager = $manager;
    $this->checker = $checker;
    $this->clock->set(1_700_000_000);
  }

  /**
   * A record is active only inside its window, and overlap is a union.
   */
  public function testActivityWindows(): void {
    $this->assertFalse($this->checker->hasPremium($this->owner, 1_700_000_000));
    $early = $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_100,
      1_700_000_200,
      'Перше вікно',
      $this->actor,
      FALSE,
    );
    $this->assertFalse($this->checker->hasPremium($this->owner, 1_700_000_099));
    $this->assertTrue($this->checker->hasPremium($this->owner, 1_700_000_100));
    $this->assertTrue($this->checker->hasPremium($this->owner, 1_700_000_199));
    $this->assertFalse($this->checker->hasPremium($this->owner, 1_700_000_200));

    $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_TRIAL,
      1_700_000_150,
      1_700_000_300,
      'Друге вікно',
      $this->actor,
      TRUE,
    );
    $this->assertCount(2, $this->checker->getActive($this->owner, 1_700_000_160));
    $this->assertTrue($this->checker->hasPremium($this->owner, 1_700_000_250));
    $this->assertFalse($this->checker->hasPremium($this->owner, 1_700_000_300));

    $open = $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_400,
      NULL,
      'Без кінця',
      $this->actor,
      FALSE,
    );
    $this->assertNull($open->getEnds());
    $this->assertTrue($this->checker->hasPremium($this->owner, 1_900_000_000));

    $this->manager->revoke($early, 'Скасовано', $this->actor);
    $this->assertFalse($this->checker->hasPremium($this->owner, 1_700_000_120));
    $this->assertTrue($this->checker->hasPremium($this->owner, 1_700_000_250));
  }

  /**
   * A payment starts after the latest end.
   *
   * An open-ended grant blocks the next payment.
   */
  public function testPaidStartStacksOrRefuses(): void {
    $this->clock->set(1_700_000_000);
    $this->assertSame(1_700_000_000, $this->manager->nextPaidStart($this->owner));
    $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_000_500,
      'Вже є доступ',
      $this->actor,
      FALSE,
    );
    $this->assertSame(1_700_000_500, $this->manager->nextPaidStart($this->owner));
    $paid = $this->manager->grantFromOrder($this->owner, 42, 1_700_000_800);
    $this->assertSame(Entitlement::SOURCE_PAYMENT, $paid->getSource());
    $this->assertSame(1_700_000_500, $paid->getStarts());
    $this->assertTrue($paid->get('granted_by')->isEmpty());
    $this->assertSame(42, $paid->getOrderId());

    $second = $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      NULL,
      'Ще один без кінця',
      $this->actor,
      FALSE,
    );
    $this->assertNull($second->getOrderId());
    $this->assertNull($this->manager->nextPaidStart($this->owner));
    try {
      $this->manager->grantFromOrder($this->owner, 43, 1_700_000_900);
      $this->fail('A payment was stored on top of an open-ended grant.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('open-ended', $exception->getMessage());
    }
    try {
      $this->manager->grantFromOrder($this->owner, 42, 1_700_000_900);
      $this->fail('The same order granted access twice.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('order', $exception->getMessage());
    }
    try {
      $this->manager->extend($paid, 1_700_000_999, 'Не можна', $this->actor);
      $this->fail('A payment record was extended.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('manual', $exception->getMessage());
    }
  }

  /**
   * Each change appends a log row, and revoking one record leaves the other.
   */
  public function testLogRecordsEachChange(): void {
    $kept = $this->manager->grantManual(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      NULL,
      'Лишається',
      $this->actor,
      FALSE,
    );
    $entitlement = $this->manager->grantManual(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_000_500,
      'Початок',
      $this->actor,
      FALSE,
    );
    $this->clock->set(1_700_000_100);
    $this->manager->extend($entitlement, 1_700_000_800, 'Довше', $this->actor);
    $this->manager->shorten($entitlement, 1_700_000_400, 'Коротше', $this->actor);
    $this->manager->revoke($entitlement, 'Скасовано', $this->actor);
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $this->assertInstanceOf(EntitlementStorage::class, $storage);
    $storage->resetCache([(int) $entitlement->id()]);
    $current = $storage->load($entitlement->id());
    $this->assertInstanceOf(Entitlement::class, $current);
    $this->assertSame(Entitlement::STATUS_REVOKED, $current->getStatus());
    $this->assertSame('Скасовано', $current->getReason());
    $this->assertFalse($current->access('delete', $this->actor));
    $log = $this->container->get(EntitlementLog::class)->forEntitlement((int) $entitlement->id());
    $this->assertSame([
      EntitlementLog::GRANT,
      EntitlementLog::EXTEND,
      EntitlementLog::SHORTEN,
      EntitlementLog::REVOKE,
    ], array_column($log, 'action'));
    $this->assertSame(1_700_000_500, $log[1]['old_ends']);
    $this->assertSame(1_700_000_800, $log[1]['new_ends']);
    $this->assertSame((int) $this->actor->id(), $log[3]['actor_uid']);
    $this->assertSame('Скасовано', $log[3]['reason']);
    $this->clock->set(1_700_000_100);
    $this->assertTrue($this->checker->hasFeatureAccess($this->owner, 'premium_criteria'));
    $this->assertTrue($this->checker->hasFeatureAccess($this->owner, 'goals'));
    $this->assertFalse($this->checker->hasFeatureAccess($this->owner, 'unknown'));
    $this->assertTrue($this->checker->hasPremium($this->owner));
    $status = $this->checker->getEffectiveStatus($this->owner);
    $this->assertSame(EffectiveStatus::PREMIUM, $status->status);
    $this->assertSame([Entitlement::SOURCE_ADMIN], $status->sources);
    $this->assertNull($status->ends);
    $this->manager->revoke($kept, 'І цей теж', $this->actor);
    $this->assertFalse($this->checker->hasPremium($this->owner));
    $expired = $this->checker->getEffectiveStatus($this->owner);
    $this->assertSame(EffectiveStatus::EXPIRED, $expired->status);
    $schema = $this->container->get('database')->schema();
    $this->assertFalse($schema->tableExists('lifebalance_entitlement_revision'));
    $this->assertTrue($schema->tableExists('lifebalance_entitlement_log'));
    $this->assertTrue($schema->tableExists('lifebalance_trial_usage'));
    $this->assertTrue($schema->indexExists(
      'lifebalance_entitlement',
      'entitlement__uid_status_ends',
    ));
  }

  /**
   * A Kyiv day ends at 23:59:59, including a daylight-saving change.
   */
  public function testEndOfDaysUsesKyivTime(): void {
    $zone = new \DateTimeZone('Europe/Kyiv');
    $start = (new \DateTimeImmutable('2021-03-27 12:00:00', $zone))->getTimestamp();
    $end = $this->manager->endOfDays($start, 2);
    $local = (new \DateTimeImmutable('@' . $end))->setTimezone($zone);
    $this->assertSame('2021-03-28 23:59:59', $local->format('Y-m-d H:i:s'));
    $start_local = (new \DateTimeImmutable('@' . $start))->setTimezone($zone);
    $this->assertNotSame($start_local->format('P'), $local->format('P'));

    $autumn = (new \DateTimeImmutable('2021-10-30 12:00:00', $zone))->getTimestamp();
    $autumn_end = $this->manager->endOfDays($autumn, 2);
    $autumn_local = (new \DateTimeImmutable('@' . $autumn_end))->setTimezone($zone);
    $this->assertSame('2021-10-31 23:59:59', $autumn_local->format('Y-m-d H:i:s'));

    $morning = (new \DateTimeImmutable('2026-10-15 08:00:00', $zone))->getTimestamp();
    $boundary = $this->manager->endOfDays($morning, 1);
    $this->manager->grantManual(
      $this->owner,
      Entitlement::SOURCE_TRIAL,
      $morning,
      $boundary,
      'Один день',
      $this->actor,
      FALSE,
    );
    $this->assertTrue($this->checker->hasPremium($this->owner, $boundary - 1));
    $this->assertFalse($this->checker->hasPremium($this->owner, $boundary));
    $trial = $this->checker->getEffectiveStatus($this->owner, $boundary - 1);
    $this->assertSame(EffectiveStatus::TRIAL, $trial->status);
    $this->assertSame($boundary, $trial->ends);
  }

  /**
   * Cache metadata expires at the next boundary, and the tag moves on save.
   */
  public function testCacheExpiresAtTheBoundary(): void {
    $uid = (int) $this->owner->id();
    $tag = 'lifebalance_entitlement:' . $uid;
    $checksums = $this->container->get('cache_tags.invalidator.checksum');
    $before = $checksums->getCurrentChecksum([$tag]);
    $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_000_300,
      'До межі',
      $this->actor,
      FALSE,
    );
    $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_TRIAL,
      1_700_000_500,
      1_700_000_900,
      'Пізніше',
      $this->actor,
      FALSE,
    );
    $after = $checksums->getCurrentChecksum([$tag]);
    $this->assertNotSame($before, $after);

    $this->clock->set(1_700_000_000);
    $meta = $this->checker->getCacheability($this->owner);
    $this->assertSame(['user'], $meta->getCacheContexts());
    $this->assertSame([$tag], $meta->getCacheTags());
    $this->assertSame(300, $meta->getCacheMaxAge());

    $this->clock->set(1_700_000_400);
    $between = $this->checker->getCacheability($this->owner);
    $this->assertSame(100, $between->getCacheMaxAge());
    $this->assertFalse($this->checker->hasPremium($this->owner));

    $this->clock->set(1_700_000_500);
    $this->assertTrue($this->checker->hasPremium($this->owner));
    $this->assertSame(400, $this->checker->getCacheability($this->owner)->getCacheMaxAge());

    $open = $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      NULL,
      'Без межі',
      $this->actor,
      FALSE,
    );
    $this->manager->revoke($open, 'Прибрано', $this->actor);
    $this->clock->set(1_700_001_000);
    $this->assertSame(
      Cache::PERMANENT,
      $this->checker->getCacheability($this->owner)->getCacheMaxAge(),
    );
  }

  /**
   * The questionnaire premium block disappears after the record ends.
   */
  public function testQuestionnaireDropsPremiumAfterTheEnd(): void {
    $criterion = Criterion::create([
      'key' => 'health.cache.premium',
      'sector' => 'health',
      'tier' => Criterion::TIER_PREMIUM,
      'text' => 'Твердження межі доступу',
      'help' => '',
      'weight' => 0,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $this->owner->id(),
    ]);
    $criterion->save();
    $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_000_120,
      'Короткий доступ',
      $this->actor,
      FALSE,
    );
    $this->clock->set(1_700_000_000);
    $form = $this->questionnaire();
    $this->assertArrayHasKey('premium_block', $form);
    $block_cache = $form['premium_block']['#cache'] ?? NULL;
    $this->assertIsArray($block_cache);
    $this->assertSame(120, $block_cache['max-age']);
    $this->assertContains(
      'lifebalance_entitlement:' . $this->owner->id(),
      $block_cache['tags'],
    );

    $this->clock->set(1_700_000_120);
    $later = $this->questionnaire();
    $this->assertArrayNotHasKey('premium_block', $later);
    $this->assertFalse($this->checker->hasPremium($this->owner));
  }

  /**
   * The expiry flag is not a revision, and a later end clears it.
   */
  public function testExtendClearsTheExpiryFlag(): void {
    $entitlement = $this->manager->grant(
      $this->owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_086_400,
      'Позначка листа',
      $this->actor,
      FALSE,
    );
    $this->manager->markExpiryNotified($entitlement);
    $stored = Entitlement::load($entitlement->id());
    $this->assertInstanceOf(Entitlement::class, $stored);
    $this->assertSame(1, (int) $stored->get('expiry_notified')->value);
    $notice = $this->container->get(EntitlementLog::class)->forEntitlement((int) $entitlement->id());
    $this->assertSame(EntitlementLog::EXPIRE_NOTICE, $notice[array_key_last($notice)]['action']);

    $this->manager->extend($stored, 1_700_864_000, 'Далі після листа', $this->actor);
    $extended = Entitlement::load($entitlement->id());
    $this->assertInstanceOf(Entitlement::class, $extended);
    $this->assertSame(0, (int) $extended->get('expiry_notified')->value);
  }

  /**
   * Builds the health questionnaire for the current person.
   *
   * @return array<string, mixed>
   *   The form.
   */
  private function questionnaire(): array {
    $form = SectorQuestionnaireForm::create($this->container);
    return $form->buildForm([], $this->formState(), 'health');
  }

  /**
   * Empty form state.
   */
  private function formState(): FormStateInterface {
    return new FormState();
  }

}
