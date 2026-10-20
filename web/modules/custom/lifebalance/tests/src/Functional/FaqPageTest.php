<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The public FAQ is the same for an anonymous and a signed-in person.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class FaqPageTest extends BrowserTestBase {

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
   * Answers are public. The footer repeats the same pages.
   */
  public function testFaqIsPublic(): void {
    $this->drupalGet('/faq');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Це самооцінка, а не діагностика.');
    $this->assertSession()->pageTextContains('Автопродовження немає.');
    $this->assertSession()->pageTextContains('Оферта і політика повернення ще є заготовками власника.');
    $this->assertSession()->linkNotExists('Конфіденційність');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->linkNotExists('Повернення');
    $this->drupalGet('/privacy');
    $this->clickLink('Запитання');
    $this->assertSession()->addressMatches('#/faq$#');

    $this->drupalGet('/terms');
    $this->clickLink('Запитання');
    $this->assertSession()->addressMatches('#/faq$#');
    $this->drupalGet('/refunds');
    $this->clickLink('Запитання');
    $this->assertSession()->addressMatches('#/faq$#');

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $this->drupalGet('/faq');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Оцінки, відповіді й нотатки бачите лише ви.');
    $this->drupalGet('/account/data');
    $this->clickLink('Запитання');
    $this->assertSession()->addressMatches('#/faq$#');
  }

}
