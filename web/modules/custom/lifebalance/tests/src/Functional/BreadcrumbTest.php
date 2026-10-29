<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Breadcrumbs name the active menu item and its submenu.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class BreadcrumbTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'lifebalance',
    'lifebalance_plans',
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
    $this->drupalPlaceBlock('system_breadcrumb_block');
  }

  /**
   * A menu page shows that item. A nested page shows the parent too.
   */
  public function testTrailFollowsTheMenu(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementNotExists('css', 'nav[aria-labelledby="system-breadcrumb"]');
    $this->drupalGet('/faq');
    $this->assertBreadcrumb(['Home', 'Запитання']);
    $this->drupalGet('/privacy');
    $this->assertBreadcrumb(['Home', 'Конфіденційність']);

    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $this->drupalGet('/history/compare');
    $this->assertBreadcrumb(['Home', 'Колесо', 'Історія', 'Порівняння']);
    $this->drupalGet('/account/data/delete');
    $this->assertBreadcrumb(['Home', 'Колесо', 'Мої дані', 'Видалити акаунт']);
    $this->drupalGet('/plan/focus');
    $this->assertBreadcrumb(['Home', 'Колесо', 'План', 'Фокус']);
  }

  /**
   * The visible crumb labels, in order.
   *
   * @param list<string> $expected
   *   Labels from the home link through the current page.
   */
  private function assertBreadcrumb(array $expected): void {
    $crumbs = $this->assertSession()->elementExists('css', 'nav[aria-labelledby="system-breadcrumb"]');
    $labels = [];
    foreach ($crumbs->findAll('css', 'li') as $item) {
      $labels[] = trim($item->getText());
    }
    $this->assertSame($expected, $labels);
  }

}
