<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Manual Premium on the admin card, the list tabs, and the expiry letter.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AdminPremiumTest extends BrowserTestBase {

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
   * Pinned instant for the seven-day grant.
   */
  private const NOW = 1_700_000_000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lifebalance'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A seven-day grant, the tabs, and one expiry letter.
   */
  public function testManualGrantListAndExpiryMail(): void {
    $clock = $this->container->get(Clock::class);
    $manager = $this->container->get(EntitlementManager::class);
    $clock->set(self::NOW);

    $member = $this->drupalCreateUser([], 'grantmember');
    $free = $this->drupalCreateUser([], 'planfree');
    $premium = $this->drupalCreateUser([], 'planpremium');
    $ending = $this->drupalCreateUser([], 'planending');
    $expired = $this->drupalCreateUser([], 'planexpired');
    $continued = $this->drupalCreateUser([], 'plancontinued');
    $payer = $this->drupalCreateUser([], 'planpaid');
    $muted = $this->drupalCreateUser([], 'planmuted');
    $support = $this->drupalCreateUser([], 'supportperson');
    $support->addRole('support_manager');
    $support->save();
    $site = $this->drupalCreateUser([], 'sitemanager');
    $site->addRole('site_manager');
    $site->save();

    $manager->grant(
      $member,
      Entitlement::SOURCE_TRIAL,
      1_600_000_000,
      1_600_000_100,
      'Минуле ознайомлення',
      $support,
      FALSE,
    );
    $manager->grant($premium, Entitlement::SOURCE_ADMIN, self::NOW, self::NOW + (30 * 86400), 'Довгий доступ', $support, FALSE);
    $manager->grant($ending, Entitlement::SOURCE_ADMIN, self::NOW, self::NOW + (2 * 86400), 'Причина листа завершення', $support, FALSE);
    $manager->grant($expired, Entitlement::SOURCE_ADMIN, 1_600_000_000, 1_690_000_000, 'Минулий доступ', $support, FALSE);
    $manager->grant($continued, Entitlement::SOURCE_ADMIN, self::NOW, self::NOW + (2 * 86400), 'Перший відрізок', $support, FALSE);
    $manager->grant($continued, Entitlement::SOURCE_ADMIN, self::NOW + (2 * 86400), self::NOW + (40 * 86400), 'Наступний відрізок', $support, FALSE);
    $paid = $manager->grantFromOrder($payer, 9001, self::NOW + (10 * 86400), self::NOW);
    $manager->grant($muted, Entitlement::SOURCE_ADMIN, self::NOW, self::NOW + (2 * 86400), 'Мовчазне нагадування', $support, FALSE);
    $this->container->get('user.data')->set('lifebalance', (int) $muted->id(), EntitlementNotifier::MUTE_NAME, 1);

    $this->container->get('current_user')->setAccount($member);
    $this->saveSnapshot($member);
    $this->saveCriterion($member);

    $grant = '/admin/lifebalance/users/' . $member->id() . '/grant';
    $this->drupalGet($grant);
    $this->assertSession()->statusCodeEquals(403);

    $stranger = $this->drupalCreateUser();
    $this->drupalLogin($stranger);
    $this->drupalGet($grant);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($site);
    $this->drupalGet($grant);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/lifebalance/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Увімкнути розширене оцінювання');
    $this->drupalGet('/history/' . $this->snapshotUuid($member));
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalLogin($support);
    $this->drupalGet($grant);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Ознайомлювальний доступ уже надавали.');
    $this->submitForm([
      'duration' => '7',
      'source' => Entitlement::SOURCE_ADMIN,
      'reason' => '   ',
    ], 'Надати');
    $this->assertSession()->pageTextContains('Вкажіть причину.');

    $this->submitForm([
      'duration' => '7',
      'source' => Entitlement::SOURCE_ADMIN,
      'reason' => 'Причина семи днів',
      'notify' => TRUE,
    ], 'Надати');
    $this->assertSession()->pageTextContains('Доступ надано.');
    $this->assertSession()->pageTextContains('Причина семи днів');
    $this->assertSession()->pageTextContains('supportperson');
    $this->assertSession()->pageTextContains('Історія');
    $this->assertSession()->pageTextNotContains('Секретна нотатка преміум');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $grant_mails = $this->mails('lifebalance_entitlement_granted');
    $this->assertCount(1, $grant_mails);
    $this->assertSame($member->getEmail(), $grant_mails[0]['to']);
    $this->assertSame('Розширене оцінювання відкрито', $grant_mails[0]['subject']);
    $this->assertStringNotContainsString('Причина семи днів', $this->body($grant_mails[0]));

    $this->drupalLogin($site);
    $this->drupalGet('/admin/lifebalance/users/' . $member->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Premium');
    $this->assertSession()->pageTextNotContains('Причина семи днів');
    $this->assertSession()->pageTextNotContains('Надати');
    $this->assertSession()->pageTextNotContains('Секретна нотатка преміум');

    $this->drupalLogin($support);
    $this->assertTab('premium', [
      'grantmember',
      'planpremium',
      'planending',
      'plancontinued',
      'planpaid',
      'planmuted',
    ], ['planfree', 'planexpired']);
    $this->assertTab('manual', [
      'grantmember',
      'planpremium',
      'planending',
      'plancontinued',
      'planmuted',
    ], ['planpaid', 'planfree', 'planexpired']);
    $this->assertTab('paid', ['planpaid'], [
      'planpremium',
      'grantmember',
      'planfree',
    ]);
    $this->assertTab('ending', [
      'grantmember',
      'planending',
      'plancontinued',
      'planmuted',
    ], ['planpremium', 'planfree']);
    $this->assertTab('free', ['planfree'], [
      'grantmember',
      'planpremium',
      'planexpired',
    ]);
    $this->assertTab('expired', ['planexpired'], [
      'grantmember',
      'planfree',
      'planpremium',
    ]);
    $this->drupalGet('/admin/lifebalance/users', ['query' => ['search' => 'grantmember']]);
    $this->submitForm([], 'Завантажити CSV');
    $this->assertSession()->responseContains('grantmember');
    $this->assertSession()->responseNotContains('Секретна нотатка преміум');
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->pageTextContains('Активний Premium:');
    $this->assertSession()->pageTextContains('Закінчується за 7 днів:');
    $this->assertSession()->pageTextNotContains('Секретна нотатка преміум');
    $this->drupalGet('/history/' . $this->snapshotUuid($member));
    $this->assertSession()->statusCodeEquals(404);

    $this->container->get(WheelCreation::class)->ensure($member);
    $this->drupalLogin($member);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextContains('Твердження семи днів');
    $this->assertSession()->pageTextContains('Розширене оцінювання');
    $this->drupalGet('/account/data');
    $this->assertSession()->pageTextContains('Доступ до');
    $this->assertSession()->pageTextContains('включно');
    $this->assertSession()->pageTextContains('Джерела: Надання');

    $this->drupalLogin($support);
    $this->drupalGet('/admin/lifebalance/users/' . $payer->id());
    $this->assertSession()->pageTextContains('Оплата');
    $this->assertSession()->linkNotExists('Продовжити');
    $this->assertSession()->linkNotExists('Скоротити');
    $this->assertSession()->linkNotExists('Скасувати');
    $extend = '/admin/lifebalance/users/' . $payer->id() . '/entitlement/' . $paid->id() . '/extend';
    $this->drupalGet($extend);
    $this->assertSession()->statusCodeEquals(403);

    $this->container->get('cron')->run();
    $expiring = $this->mails('lifebalance_entitlement_expiring');
    $this->assertCount(1, $expiring);
    $this->assertSame($ending->getEmail(), $expiring[0]['to']);
    $this->assertSame('Розширене оцінювання завершується', $expiring[0]['subject']);
    $this->assertStringNotContainsString('Причина листа завершення', $this->body($expiring[0]));
    $this->container->get('cron')->run();
    $this->assertCount(1, $this->mails('lifebalance_entitlement_expiring'));

    $clock->set(self::NOW + (7 * 86400));
    $this->drupalLogin($member);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextNotContains('Твердження семи днів');
    $this->drupalGet('/account/data');
    $this->assertSession()->pageTextContains('Розширене оцінювання: недоступне');

    $this->drupalLogin($support);
    $this->drupalGet('/admin/lifebalance/users/' . $member->id());
    $this->assertSession()->pageTextContains('Причина семи днів');
    $this->assertSession()->pageTextContains('supportperson');
    $this->assertSession()->pageTextNotContains('Секретна нотатка преміум');
    $this->assertNotNull($free->id());
    $this->assertNotNull($premium->id());
  }

  /**
   * One tab contains the expected names and hides the others.
   *
   * @param string $tab
   *   Allowlisted tab key.
   * @param list<string> $present
   *   Names that belong on the tab.
   * @param list<string> $absent
   *   Names that do not.
   */
  private function assertTab(string $tab, array $present, array $absent): void {
    $this->drupalGet('/admin/lifebalance/users', ['query' => ['tab' => $tab]]);
    $this->assertSession()->statusCodeEquals(200);
    foreach ($present as $name) {
      $this->assertSession()->pageTextContains($name);
    }
    foreach ($absent as $name) {
      $this->assertSession()->pageTextNotContains($name);
    }
  }

  /**
   * Collected letters of one id.
   *
   * @return list<array<string, mixed>>
   *   Matching messages.
   */
  private function mails(string $id): array {
    $captured = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertIsArray($captured);
    $matched = [];
    foreach ($captured as $mail) {
      if (is_array($mail) && ($mail['id'] ?? '') === $id) {
        $matched[] = $mail;
      }
    }
    return $matched;
  }

  /**
   * Letter body as one string.
   *
   * @param array<string, mixed> $mail
   *   One collected message.
   */
  private function body(array $mail): string {
    $body = $mail['body'] ?? '';
    if (is_array($body)) {
      return implode("\n", array_map(static fn ($line): string => is_scalar($line) ? (string) $line : '', $body));
    }
    return is_scalar($body) ? (string) $body : '';
  }

  /**
   * Stores eight sector scores and a private note.
   */
  private function saveSnapshot(UserInterface $owner): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 6,
      ];
    }
    Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Секретна нотатка преміум',
      'scores' => $scores,
    ])->save();
  }

  /**
   * One published premium statement.
   */
  private function saveCriterion(UserInterface $owner): void {
    Criterion::create([
      'key' => 'health.page.seven',
      'sector' => 'health',
      'tier' => Criterion::TIER_PREMIUM,
      'text' => 'Твердження семи днів',
      'help' => '',
      'weight' => 0,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ])->save();
  }

  /**
   * UUID of the person's only snapshot.
   */
  private function snapshotUuid(UserInterface $owner): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner->id())
      ->execute();
    $id = reset($ids);
    $this->assertNotFalse($id);
    $snapshot = $storage->load($id);
    $this->assertInstanceOf(Snapshot::class, $snapshot);
    return (string) $snapshot->uuid();
  }

}
