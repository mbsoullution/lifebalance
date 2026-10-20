<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Functional;

use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The print page is private. The banner waits, and stats stay aggregate.
 */
#[Group('lifebalance_plans')]
#[RunTestsInSeparateProcesses]
final class PrintPageTest extends BrowserTestBase {

  use EntitlementTestTrait;

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
    'lifebalance',
    'lifebalance_plans',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Print, the 7/30 banner, and counts without goal text.
   */
  public function testPrintAndBanner(): void {
    $this->drupalGet('/plan/print');
    $this->assertSession()->addressMatches('#/user/login#');

    $member = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();
    $this->drupalLogin($member);
    $this->drupalGet('/plan/print');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Зрізу ще немає.');
    $this->assertSession()->pageTextContains('Активних цілей ще немає.');
    $this->assertSession()->pageTextContains('Друк / Зберегти як PDF');
    $this->assertSession()->elementExists('css', '[data-lifebalance-print]');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $this->grantEntitlement($member);
    $this->drupalLogin($member);
    $this->drupalGet('/plan/add');
    $this->submitForm([
      'sector' => 'health',
      'title' => 'Ціль для друку 44190',
      'instructions' => 'Опис цілі для друку.',
      'steps[0]' => 'Зробити крок друку',
    ], 'Додати ціль');
    $this->assertSession()->pageTextContains('Ціль додано до плану.');
    $this->storeSnapshot($member);

    $this->drupalGet('/plan');
    $this->assertSession()->pageTextNotContains('Переоцінити сферу');
    $this->clickLink('Версія для друку');
    $this->assertSession()->addressEquals('/plan/print');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'svg.lifebalance-radar');
    $this->assertSession()->pageTextContains('Ціль для друку 44190');
    $this->assertSession()->pageTextContains('Ще ні: Зробити крок друку');
    $this->assertSession()->pageTextContains('Нотатка друку 91827');
    $this->assertSession()->pageTextContains("Здоров'я");
    $this->assertSession()->pageTextNotContains('Переоцінити сферу');

    $this->storePlans($member);
    $this->drupalGet('/plan');
    $this->assertSession()->pageTextContains('Переоцінити сферу «' . "Здоров'я" . '»?');
    $this->assertSession()->linkByHrefExists('/wheel/health');
    $this->assertSession()->pageTextNotContains('Минуло 7 днів');
    $this->drupalGet('/plan/print');
    $this->assertSession()->pageTextContains('План на 30 днів до');
    $this->assertSession()->pageTextContains('Залишилось днів:');
    $this->assertSession()->pageTextContains('У цьому плані цілей ще немає.');
    $this->assertSession()->pageTextContains('Ціль для друку 44190');

    $goal = $this->onlyGoal((int) $member->id());
    $clock = $this->container->get(Clock::class);
    $clock->set($goal->getCreatedTime() + (8 * 86400));
    $this->drupalGet('/plan');
    $this->assertSession()->pageTextContains('Минуло 7 днів від цілі у сфері');
    $this->assertSession()->linkByHrefExists('/wheel/health');

    $clock->set($goal->getCreatedTime() + (31 * 86400));
    $this->drupalGet('/plan');
    $this->assertSession()->pageTextContains('Минуло 30 днів від цілі у сфері');
    $this->assertSession()->pageTextNotContains('Минуло 7 днів');

    $this->drupalLogin($other);
    $this->drupalGet('/plan/print');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Ціль для друку 44190');
    $this->assertSession()->pageTextNotContains('Нотатка друку 91827');
    $this->assertSession()->pageTextNotContains('План на 30 днів до');
    $this->assertSession()->pageTextContains('Зрізу ще немає.');

    $staff = $this->drupalCreateUser(['view lifebalance user metadata']);
    $this->drupalLogin($staff);
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Користувачі з цілями: 1');
    $this->assertSession()->pageTextContains('Виконані цілі: 0');
    $this->assertSession()->pageTextContains('Зареєстровані:');
    $this->assertSession()->pageTextContains('Повернулися через 30 днів:');
    $this->assertSession()->pageTextNotContains('Ціль для друку 44190');
    $this->assertSession()->pageTextNotContains('Нотатка друку 91827');
  }

  /**
   * A completed plan invites reassessment. An active plan is printable.
   */
  private function storePlans(UserInterface $member): void {
    $this->container->get('current_user')->setAccount($member);
    $now = $this->container->get(Clock::class)->now();
    Plan::create([
      'uid' => $member->id(),
      'focus_sectors' => ['health'],
      'starts' => $now - (40 * 86400),
      'ends' => $now - 86400,
      'status' => Plan::STATUS_COMPLETED,
      'invite_open' => 1,
      'completion_mailed' => 1,
    ])->save();
    Plan::create([
      'uid' => $member->id(),
      'focus_sectors' => ['finances'],
      'starts' => $now,
      'ends' => $now + (20 * 86400),
      'status' => Plan::STATUS_ACTIVE,
    ])->save();
  }

  /**
   * Stores one snapshot owned by the member.
   */
  private function storeSnapshot(UserInterface $member): void {
    $this->container->get('current_user')->setAccount($member);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => $sector === 'health' ? 4 : 8,
      ];
    }
    Snapshot::create([
      'uid' => $member->id(),
      'note' => 'Нотатка друку 91827',
      'scores' => $scores,
    ])->save();
  }

  /**
   * The only goal of this owner.
   */
  private function onlyGoal(int $uid): Goal {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    $this->assertCount(1, $ids);
    $goal = $storage->load(reset($ids));
    $this->assertInstanceOf(Goal::class, $goal);
    return $goal;
  }

}
