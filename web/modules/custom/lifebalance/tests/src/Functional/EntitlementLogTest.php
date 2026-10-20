<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The entitlement log is limited to support and hides personal answers.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class EntitlementLogTest extends BrowserTestBase {

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
  protected static $modules = ['lifebalance'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Support sees the audit row. Other accounts and the note stay out.
   */
  public function testSupportSeesTheAuditRow(): void {
    $member = $this->drupalCreateUser([], 'logmember');
    $member->setEmail('log-member@example.com');
    $member->save();
    $support = $this->drupalCreateUser([], 'logsupport');
    $support->addRole('support_manager');
    $support->save();
    $site = $this->drupalCreateUser([], 'logsite');
    $site->addRole('site_manager');
    $site->save();
    $stranger = $this->drupalCreateUser();

    $this->container->get('current_user')->setAccount($member);
    $this->saveSnapshot($member);
    $this->container->get(EntitlementManager::class)->grant(
      $member,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      1_700_086_400,
      'Причина журналу 551',
      $support,
      FALSE,
    );

    $this->drupalGet('/admin/lifebalance/entitlement-log');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($stranger);
    $this->drupalGet('/admin/lifebalance/entitlement-log');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($site);
    $this->drupalGet('/admin/lifebalance/entitlement-log');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($support);
    $this->drupalGet('/admin/lifebalance/entitlement-log');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('logmember');
    $this->assertSession()->pageTextContains('logsupport');
    $this->assertSession()->pageTextContains('Надано');
    $this->assertSession()->pageTextContains('Причина журналу 551');
    $this->assertSession()->pageTextNotContains('Секретна нотатка журналу');
    $this->assertSession()->pageTextNotContains('log-member@example.com');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $this->drupalGet('/admin/lifebalance/entitlement-log', [
      'query' => ['action' => 'extend'],
    ]);
    $this->assertSession()->pageTextNotContains('Причина журналу 551');
    $this->drupalGet('/admin/lifebalance/entitlement-log', [
      'query' => [
        'action' => 'grant',
        'target' => $member->id(),
        'actor' => $support->id(),
      ],
    ]);
    $this->assertSession()->pageTextContains('Причина журналу 551');
    $this->assertSession()->pageTextNotContains('Секретна нотатка журналу');
  }

  /**
   * Stores eight sector scores and a private note.
   */
  private function saveSnapshot(UserInterface $owner): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 4,
      ];
    }
    Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Секретна нотатка журналу',
      'scores' => $scores,
    ])->save();
  }

}
