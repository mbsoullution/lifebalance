<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\social_auth\Entity\SocialAuth;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The account page shows only the current person's providers.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AccountDataTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'lifebalance',
    'social_auth',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous visitors are sent to login, and providers stay with the owner.
   */
  public function testAccountData(): void {
    $this->drupalGet('user/password');
    $this->assertSession()->statusCodeEquals(200);

    $this->config('system.site')->set('page.front', '/welcome')->save();
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Оцініть вісім сфер життя');
    $this->assertSession()->linkExists('Увійти через Google');
    $this->assertSession()->linkExists('Увійти з паролем');

    $this->drupalGet('/login/email-match');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains("Увійдіть наявним способом і прив'яжіть його в налаштуваннях.");

    $this->drupalGet('/account/data');
    $this->assertSession()->addressMatches('#/user/login#');

    $owner = $this->drupalCreateUser();
    $owner->set('mail', NULL);
    $owner->save();
    $only = SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_google',
      'provider_user_id' => 'google-owner',
      'token' => 'token',
    ]);
    $only->save();

    $other = $this->drupalCreateUser();
    $foreign = SocialAuth::create([
      'user_id' => $other->id(),
      'plugin_id' => 'social_auth_facebook',
      'provider_user_id' => 'facebook-other',
      'token' => 'token',
    ]);
    $foreign->save();

    $this->drupalLogin($owner);
    $this->drupalGet('/account/data');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Google');
    $this->assertSession()->linkExists("Прив'язати Facebook");
    $this->assertSession()->pageTextContains('Це останній спосіб входу. Відв\'язати його не можна.');
    $this->assertSession()->buttonNotExists('Відв\'язати Google');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $second = SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_facebook',
      'provider_user_id' => 'facebook-owner',
      'token' => 'token',
    ]);
    $second->save();
    $this->drupalGet('/account/data');
    $this->assertSession()->pageTextContains('Facebook');
    $this->submitForm([], 'Відв\'язати Facebook');
    $this->assertSession()->pageTextContains('Спосіб входу відв\'язано.');
    $this->assertNull(SocialAuth::load($second->id()));
    $this->assertNotNull(SocialAuth::load($only->id()));
    $this->assertNotNull(SocialAuth::load($foreign->id()));

    $this->drupalLogin($other);
    $this->drupalGet('/account/data');
    $this->assertSession()->pageTextContains('Facebook');
    $this->assertSession()->pageTextNotContains('Відв\'язати Google');
    $this->assertNotNull(SocialAuth::load($only->id()));
  }

}
