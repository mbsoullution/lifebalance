<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The questionnaire stops showing Premium after the record ends.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class EntitlementCacheTest extends BrowserTestBase {

  use EntitlementTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Moving the clock past the end hides the premium block.
   */
  public function testPremiumBlockExpires(): void {
    $owner = $this->drupalCreateUser();
    $clock = $this->container->get(Clock::class);
    $manager = $this->container->get(EntitlementManager::class);
    $clock->set(1_700_000_000);
    $criterion = Criterion::create([
      'key' => 'health.page.premium',
      'sector' => 'health',
      'tier' => Criterion::TIER_PREMIUM,
      'text' => 'Твердження межі доступу',
      'help' => '',
      'weight' => 0,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    $manager->grant(
      $owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_000_100,
      'Короткий доступ',
      $owner,
      FALSE,
    );

    $this->container->get(WheelCreation::class)->ensure($owner);
    $this->drupalLogin($owner);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Твердження межі доступу');
    $this->assertSession()->pageTextContains('Розширене оцінювання');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $clock->set(1_700_000_100);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Твердження межі доступу');
    $this->assertSession()->pageTextNotContains('Розширене оцінювання');
  }

}
