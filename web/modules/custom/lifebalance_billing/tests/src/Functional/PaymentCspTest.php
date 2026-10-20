<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The payment host is allowed only for navigation, not for scripts.
 */
#[Group('lifebalance_billing')]
#[RunTestsInSeparateProcesses]
final class PaymentCspTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'lifebalance',
    'lifebalance_billing',
    'seckit',
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
    $storage = new FileStorage(dirname(__DIR__, 7) . '/config/sync');
    $seckit = $storage->read('seckit.settings');
    $this->assertIsArray($seckit);
    $this->config('seckit.settings')->setData($seckit)->save();
  }

  /**
   * Form-action and navigate-to name the hosted payment page.
   */
  public function testPaymentHostIsNavigationOnly(): void {
    $this->drupalGet('/privacy');
    $this->assertSession()->statusCodeEquals(200);
    $policy = $this->header('Content-Security-Policy');
    $this->assertStringContainsString("default-src 'self'", $policy);
    $this->assertStringContainsString("form-action 'self' https://accounts.google.com https://www.facebook.com https://pay.mbnk.biz", $policy);
    $this->assertStringContainsString("navigate-to 'self' https://accounts.google.com https://www.facebook.com https://pay.mbnk.biz", $policy);
    $this->assertStringContainsString("script-src 'self'", $policy);
    $this->assertStringNotContainsString("script-src 'self' https://pay.mbnk.biz", $policy);
    $this->assertStringNotContainsString('https://pay.mbnk.biz', $this->directive($policy, 'script-src'));
    $this->assertStringNotContainsString('https://pay.mbnk.biz', $this->directive($policy, 'default-src'));
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
   * The value of one CSP directive.
   */
  private function directive(string $policy, string $name): string {
    foreach (explode(';', $policy) as $part) {
      $part = trim($part);
      if (str_starts_with($part, $name . ' ')) {
        return $part;
      }
    }
    throw new \RuntimeException($name . ' directive is missing.');
  }

}
