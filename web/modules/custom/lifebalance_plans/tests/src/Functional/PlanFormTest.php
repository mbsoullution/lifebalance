<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Functional;

use Drupal\lifebalance_plans\Entity\Goal;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The plan is private. New goals require Premium. Existing ones stay.
 */
#[Group('lifebalance_plans')]
#[RunTestsInSeparateProcesses]
final class PlanFormTest extends BrowserTestBase {

  use EntitlementTestTrait;

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
   * Tabs, an own goal, and owner-only access.
   */
  public function testPlan(): void {
    $this->drupalGet('/plan');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/plan/add');
    $this->assertSession()->addressMatches('#/user/login#');

    $member = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();
    $this->drupalLogin($member);
    $this->drupalGet('/plan');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('У цьому переліку цілей ще немає.');
    $this->assertSession()->pageTextContains('Плану на 30 днів ще немає.');
    $this->assertSession()->pageTextNotContains('Завершити достроково');
    $this->assertSession()->pageTextContains('Активні');
    $this->assertSession()->pageTextContains('30 днів');
    $this->assertSession()->pageTextContains('90 днів');
    $this->assertSession()->pageTextContains('Виконані/скасовані');
    $this->assertSession()->pageTextNotContains('Додати власну ціль');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
    $this->drupalGet('/plan/add');
    $this->assertSession()->statusCodeEquals(403);

    $this->grantEntitlement($member);
    $this->drupalLogin($member);
    $due = (new \DateTimeImmutable('+10 days', new \DateTimeZone('UTC')))->format('Y-m-d');
    $this->drupalGet('/plan/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'sector' => 'health',
      'title' => 'Власна ціль тесту',
      'instructions' => 'Один власний опис.',
      'steps[0]' => 'Зробити один крок',
      'due' => $due,
    ], 'Додати ціль');
    $this->assertSession()->pageTextContains('Ціль додано до плану.');
    $this->assertSession()->pageTextContains('Власна ціль тесту');
    $this->assertSession()->pageTextContains('Один власний опис.');
    $goal = $this->onlyGoal((int) $member->id());
    $this->drupalGet('/plan/30');
    $this->assertSession()->pageTextContains('Власна ціль тесту');
    $this->drupalGet('/plan/done');
    $this->assertSession()->pageTextNotContains('Власна ціль тесту');

    $this->drupalGet('/plan/' . $goal->uuid());
    $this->submitForm([
      'status' => Goal::STATUS_DONE,
      'steps[0]' => '0',
    ], 'Оновити ціль');
    $this->assertSession()->pageTextContains('Ціль оновлено.');
    $this->drupalGet('/plan');
    $this->assertSession()->pageTextNotContains('Власна ціль тесту');
    $this->drupalGet('/plan/done');
    $this->assertSession()->pageTextContains('Власна ціль тесту');

    $this->drupalLogin($other);
    $this->drupalGet('/plan/' . $goal->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Один власний опис.');
    foreach (['site_manager', 'support_manager', 'content_editor'] as $role) {
      $staff = $this->drupalCreateUser();
      $staff->addRole($role);
      $staff->save();
      $this->drupalLogin($staff);
      $this->drupalGet('/plan/' . $goal->uuid());
      $this->assertSession()->statusCodeEquals(404);
      $this->assertSession()->pageTextNotContains('Власна ціль тесту');
    }
    $root = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $root);
    $this->drupalLogin($root);
    $this->drupalGet('/plan/' . $goal->uuid());
    $this->assertSession()->statusCodeEquals(404);

    $this->revokeEntitlements($member);
    $this->drupalLogin($member);
    $this->drupalGet('/plan/add');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/plan/done');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Власна ціль тесту');
    $this->drupalGet('/plan/' . $goal->uuid());
    $this->submitForm([
      'status' => Goal::STATUS_CANCELLED,
    ], 'Оновити ціль');
    $this->assertSession()->pageTextContains('Ціль оновлено.');
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
