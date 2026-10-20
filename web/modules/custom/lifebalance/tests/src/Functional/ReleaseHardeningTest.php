<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Security headers, public metatags, and the password-reset mail.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class ReleaseHardeningTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'lifebalance',
    'seckit',
    'social_auth_google',
    'social_auth_facebook',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $storage = new FileStorage($this->projectRoot() . '/config/sync');
    $seckit = $storage->read('seckit.settings');
    $this->assertIsArray($seckit);
    $this->config('seckit.settings')->setData($seckit)->save();
    $front = $storage->read('metatag.metatag_defaults.front');
    $this->assertIsArray($front);
    $this->config('metatag.metatag_defaults.front')->setData($front)->save();
    $this->config('system.site')->set('page.front', '/welcome')->save();
  }

  /**
   * Public pages carry the release headers and only the intended tags.
   */
  public function testHeadersAndPublicMetatags(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $policy = $this->header('Content-Security-Policy');
    $this->assertStringContainsString("default-src 'self'", $policy);
    $this->assertStringContainsString('https://accounts.google.com', $policy);
    $this->assertStringContainsString('https://www.facebook.com', $policy);
    $this->assertStringContainsString("script-src 'self'", $policy);
    $this->assertStringContainsString('https://www.googletagmanager.com', $policy);
    $this->assertStringContainsString('https://*.google-analytics.com', $policy);
    $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    $hsts = $this->header('Strict-Transport-Security');
    $this->assertStringContainsString('max-age=31536000', $hsts);
    $this->assertStringNotContainsString('preload', $hsts);
    $this->assertStringNotContainsString('includeSubDomains', $hsts);
    $this->assertSame(
      'strict-origin-when-cross-origin',
      $this->header('Referrer-Policy'),
    );
    $this->assertSame('DENY', $this->header('X-Frame-Options'));
    $front = 'Самооцінка восьми сфер життя. Результат не є діагностикою.';
    $this->assertSession()->responseContains($front);
    $this->assertSession()->linkExists('Увійти через Google');
    $this->assertSession()->linkExists('Увійти через Facebook');

    $this->assertSession()->responseContains('index, follow');
    $this->assertSession()->responseContains('hreflang="uk"');
    $this->assertSession()->responseContains('hreflang="ru"');
    $this->assertSession()->responseContains('hreflang="en"');
    $this->assertSession()->responseContains('hreflang="x-default"');
    $this->assertSession()->responseContains('property="og:title"');

    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseNotContains($front);
    $this->assertSession()->responseContains('noindex, nofollow');

    $this->drupalGet('/sitemap.xml');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('/faq');
    $this->assertSession()->responseNotContains('/wheel');
    $this->assertSession()->responseNotContains('/history');

    $this->drupalGet('/privacy');
    $this->assertSession()->statusCodeEquals(200);
    $privacy = 'Як зберігається самооцінка і як видалити акаунт.';
    $this->assertSession()->responseContains($privacy);
    $this->assertSession()->responseNotContains($front);
  }

  /**
   * The password form produces a collected reset message.
   */
  public function testPasswordResetMailIsCollected(): void {
    $account = $this->drupalCreateUser();
    $this->drupalGet('/user/password');
    $this->submitForm([
      'name' => $account->getAccountName(),
    ], 'Submit');
    $this->assertSession()->statusCodeEquals(200);
    $state = $this->container->get('state');
    $captured = $state->get('system.test_mail_collector', []);
    $this->assertIsArray($captured);
    $this->assertNotEmpty($captured);
    $mail = $captured[array_key_last($captured)];
    $this->assertIsArray($mail);
    $this->assertSame('user_password_reset', $mail['id']);
    $this->assertSame($account->getEmail(), $mail['to']);
  }

  /**
   * One response header, matched without regard to case.
   */
  private function header(string $name): string {
    $headers = array_change_key_case($this->getSession()->getResponseHeaders());
    $values = $headers[strtolower($name)] ?? NULL;
    $this->assertIsArray($values, $name . ' header is missing.');
    return implode(' ', $values);
  }

  /**
   * Absolute project root, above the Drupal docroot.
   */
  private function projectRoot(): string {
    return dirname(__DIR__, 7);
  }

}
