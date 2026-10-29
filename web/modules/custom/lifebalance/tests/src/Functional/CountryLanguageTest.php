<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\lifebalance\Install\InterfaceLanguage;
use Drupal\lifebalance\Service\VisitorCountry;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The public page follows the visitor country, not the browser language.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class CountryLanguageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
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
    InterfaceLanguage::apply();
  }

  /**
   * Ukraine, Russia, and other countries pick the interface language.
   */
  public function testCountryChoosesTheInterface(): void {
    $state = \Drupal::state();
    $state->set(VisitorCountry::STATE_KEY, 'UA');
    $this->drupalGet('/welcome', [], ['Accept-Language' => 'en']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Створити власне колесо');
    $this->assertSession()->pageTextContains("Здоров'я");
    $this->assertSession()->pageTextNotContains('Create your own wheel');

    $state->set(VisitorCountry::STATE_KEY, 'RU');
    $this->drupalGet('/welcome', [], ['Accept-Language' => 'en']);
    $this->assertSession()->pageTextContains('Создать собственное колесо');
    $this->assertSession()->pageTextContains('Здоровье');
    $this->assertSession()->pageTextNotContains('Створити власне колесо');

    $state->set(VisitorCountry::STATE_KEY, 'DE');
    $this->drupalGet('/welcome', [], ['Accept-Language' => 'uk-UA,uk;q=0.9']);
    $this->assertSession()->pageTextContains('Create your own wheel');
    $this->assertSession()->pageTextContains('Health');
    $this->assertSession()->pageTextNotContains('Створити власне колесо');

    $this->drupalGet('/welcome', ['query' => ['language' => 'uk']]);
    $this->assertSession()->pageTextContains('Створити власне колесо');
    $select = $this->assertSession()->selectExists('language');
    $this->assertSame(['UA', 'RU', 'EN'], $this->optionLabels($select));
    $russian = $select->find('css', 'option[value="ru"]');
    $this->assertNotNull($russian);
    $target = $russian->getAttribute('data-url');
    $this->assertIsString($target);
    $parts = parse_url($target);
    $this->assertIsArray($parts);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $this->assertSame('ru', $query['language'] ?? NULL);
    $this->drupalGet($parts['path'] ?? '/', ['query' => $query]);
    $this->assertSession()->pageTextContains('Создать собственное колесо');
    $state->delete(VisitorCountry::STATE_KEY);
  }

  /**
   * Visible labels of a select, in order.
   *
   * @return list<string>
   *   Option labels.
   */
  private function optionLabels(NodeElement $select): array {
    $labels = [];
    foreach ($select->findAll('css', 'option') as $option) {
      $labels[] = $option->getText();
    }
    return $labels;
  }

}
