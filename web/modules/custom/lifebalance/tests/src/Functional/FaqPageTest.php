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
    $this->assertSession()->pageTextContains('Простий інструмент самоспостереження.');
    $this->assertSession()->pageTextContains('не валідований психологічний тест');
    $this->assertSession()->pageTextNotContains('149 грн');
    $this->assertSession()->pageTextNotContains('Спробувати Premium 7 днів');
    $this->assertSession()->pageTextNotContains('[TODO');
    $this->assertSession()->linkNotExists('Конфіденційність');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->linkNotExists('Повернення');
    $this->drupalGet('/privacy');
    $this->assertSession()->linkNotExists('Запитання');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->linkNotExists('Повернення');

    $this->drupalGet('/terms');
    $this->assertSession()->linkNotExists('Запитання');
    $this->assertSession()->linkNotExists('Повернення');
    $this->assertSession()->linkNotExists('Конфіденційність');
    $this->drupalGet('/refunds');
    $this->assertSession()->linkNotExists('Запитання');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->linkNotExists('Конфіденційність');

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $this->drupalGet('/faq');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Лише ви.');
    $this->assertSession()->pageTextContains('Власник сайту може відкрити збережене колесо');
    $this->drupalGet('/account/data');
    $this->assertSession()->linkNotExists('Запитання');
    $this->assertSession()->linkNotExists('Конфіденційність');
  }

}
