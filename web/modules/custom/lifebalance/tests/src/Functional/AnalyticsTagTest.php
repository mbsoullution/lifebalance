<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The visit counter is absent until a measurement id is saved.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AnalyticsTagTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.site')->set('page.front', '/welcome')->save();
  }

  /**
   * Public pages receive the tag. Login and an invalid id do not.
   */
  public function testTagFollowsTheMeasurementId(): void {
    $this->drupalGet('/welcome');
    $this->assertSession()->responseNotContains('googletagmanager.com');

    $admin = $this->drupalCreateUser(['administer lifebalance settings']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/lifebalance/settings');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'ga_measurement_id' => 'not-an-id',
    ], 'Зберегти');
    $this->assertSession()->pageTextContains('Ідентифікатор має виглядати як G-XXXXXXXX.');

    $this->submitForm([
      'ga_measurement_id' => 'g-test1234',
    ], 'Зберегти');
    $this->assertSame(
      'G-TEST1234',
      $this->config('lifebalance.settings')->get('ga_measurement_id'),
    );

    $this->drupalLogout();
    $this->drupalGet('/welcome');
    $this->assertSession()->responseContains(
      'https://www.googletagmanager.com/gtag/js?id=G-TEST1234',
    );
    $this->assertSession()->responseContains('data-measurement-id="G-TEST1234"');

    $this->drupalGet('/user/login');
    $this->assertSession()->responseNotContains('googletagmanager.com');

    $this->drupalGet('/privacy');
    $this->assertSession()->pageTextContains('Google Analytics');
  }

}
