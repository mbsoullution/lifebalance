<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The front page shows a sample wheel and the create action.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class LandingPageTest extends BrowserTestBase {

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
   * The sample is public, and the create link leads toward the wheel.
   */
  public function testSampleWheelAndCreateLink(): void {
    $this->drupalGet('/welcome');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Що таке колесо балансу');
    $this->assertSession()->pageTextContains('Колесо балансу — простий інструмент самоспостереження.');
    $this->assertSession()->pageTextContains('Чесно про межі');
    $this->assertSession()->pageTextNotContains('Приклад колеса');
    $this->assertSession()->elementNotExists('css', 'table');
    $html = $this->getSession()->getPage()->getHtml();
    $intro = strpos($html, 'простий інструмент самоспостереження');
    $wheel = strpos($html, 'lifebalance-landing__wheel');
    $areas = strpos($html, 'Вісім сфер');
    $this->assertNotFalse($intro);
    $this->assertNotFalse($wheel);
    $this->assertNotFalse($areas);
    $this->assertGreaterThan($intro, $wheel);
    $this->assertGreaterThan($wheel, $areas);
    $fills = [];
    foreach ($this->getSession()->getPage()->findAll('css', 'svg.lifebalance-radar path[fill-opacity="1"]') as $path) {
      $fills[] = (string) $path->getAttribute('fill');
    }
    $this->assertCount(8, $fills);
    $this->assertCount(8, array_unique($fills));
    $this->assertSession()->pageTextContains("Здоров'я");
    $this->assertSession()->linkExists('Створити власне колесо');
    $this->assertSession()->linkNotExists('Запитання');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->fieldExists("Ім'я або пошта");
    $this->assertSession()->fieldExists('Пароль');
    $this->assertSession()->buttonExists('Увійти');
    $this->clickLink('Створити власне колесо');
    $this->assertSession()->addressMatches('#/user/login#');

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $this->drupalGet('/welcome');
    $this->assertSession()->linkExists('Створити власне колесо');
    $this->assertSession()->fieldNotExists('Пароль');
    $this->clickLink('Створити власне колесо');
    $this->assertSession()->addressMatches('#/wheel$#');
    $this->submitForm([], 'Створити моє колесо');
    $this->drupalGet('/welcome');
    $this->assertSession()->linkExists('Створити власне колесо');
    $this->assertSession()->linkNotExists('До колеса');
  }

}
