<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Snapshot;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The trial button waits for a snapshot and confirms once.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class TrialFormTest extends BrowserTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous visitors log in. A confirmed start cannot be repeated.
   */
  public function testConfirmOnce(): void {
    $this->drupalGet('/account/trial');
    $this->assertSession()->addressMatches('#/user/login#');

    $member = $this->drupalCreateUser();
    $this->config('lifebalance.settings')->set('trial_enabled', TRUE)->save();
    $this->drupalLogin($member);
    $this->drupalGet('/account/trial');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Спочатку збережіть зріз.');
    $this->assertSession()->buttonNotExists('Спробувати Premium 7 днів');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $this->storeSnapshot($member, 'Нотатка ознайомлення 77110');
    $this->config('lifebalance.settings')->set('trial_enabled', FALSE)->save();
    $this->drupalGet('/wheel');
    $this->assertSession()->linkNotExists('Спробувати Premium 7 днів');
    $this->drupalGet('/account/trial');
    $this->assertSession()->pageTextContains('Ознайомлення зараз вимкнено.');

    $this->config('lifebalance.settings')->set('trial_enabled', TRUE)->save();
    $this->drupalGet('/wheel');
    $this->clickLink('Спробувати Premium 7 днів');
    $this->assertSession()->addressEquals('/account/trial');
    $this->assertSession()->pageTextContains('Картка не потрібна. Списань немає.');
    $this->assertSession()->pageTextContains('Збережені зрізи, відповіді, цілі й плани лишаються.');
    $this->submitForm([], 'Спробувати Premium 7 днів');
    $this->assertSession()->pageTextContains('Підтвердьте старт ознайомлення.');
    $this->submitForm([
      'confirm' => TRUE,
    ], 'Спробувати Premium 7 днів');
    $this->assertSession()->addressEquals('/wheel');
    $this->assertSession()->pageTextContains('Ознайомлення на 7 днів розпочато.');
    $this->assertSession()->linkNotExists('Спробувати Premium 7 днів');

    $this->drupalGet('/account/trial');
    $this->assertSession()->pageTextContains('Ознайомлення вже використано.');
    $this->assertSession()->buttonNotExists('Спробувати Premium 7 днів');
    $this->assertSession()->pageTextNotContains('Нотатка ознайомлення 77110');

    $other = $this->drupalCreateUser();
    $this->drupalLogin($other);
    $this->storeSnapshot($other, 'Чужа нотатка 77111');
    $this->drupalGet('/account/trial');
    $this->assertSession()->pageTextContains('Картка не потрібна. Списань немає.');
    $this->assertSession()->pageTextNotContains('Нотатка ознайомлення 77110');
  }

  /**
   * Stores eight sector scores and a private note.
   */
  private function storeSnapshot(UserInterface $member, string $note): void {
    $this->container->get('current_user')->setAccount($member);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 5,
      ];
    }
    Snapshot::create([
      'uid' => $member->id(),
      'note' => $note,
      'scores' => $scores,
    ])->save();
    $this->container->get('user.data')->set(
      'lifebalance',
      (int) $member->id(),
      'wheel_created',
      1,
    );
  }

}
