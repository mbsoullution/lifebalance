<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The radar updates in the browser, and the draft survives reload.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class WheelJavascriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lifebalance'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!getenv('MINK_DRIVER_ARGS_WEBDRIVER')) {
      $this->markTestSkipped('Set MINK_DRIVER_ARGS_WEBDRIVER to run the browser smoke test.');
    }
    parent::setUp();
  }

  /**
   * A stored score draws the radar. The wheel has no slider.
   */
  public function testStoredScoreDrawsTheRadar(): void {
    $owner = $this->drupalCreateUser();
    $this->container->get(WheelCreation::class)->ensure($owner);
    $this->drupalLogin($owner);
    $this->drupalGet('/wheel');
    $this->assertSame('', $this->polygonPoints());
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');

    $scores = [];
    $sectors = [
      'health',
      'relationships',
      'environment',
      'purpose',
      'finances',
      'self_development',
      'life_brightness',
      'spirituality',
    ];
    foreach ($sectors as $sector) {
      $scores[$sector] = NULL;
    }
    $scores['health'] = 4;
    $this->container->get(DraftStorage::class)->saveScores($owner, $scores, '');
    $this->drupalGet('/wheel');
    $this->assertSession()->pageTextNotContains('Показати орієнтир');
    $this->assertNotSame('', $this->polygonPoints());
    $this->assertSame(0, $this->snapshotCount($owner));
  }

  /**
   * Answering statements saves the draft and leaves the stored score.
   */
  public function testAnswersUpdateTheGuide(): void {
    $owner = $this->drupalCreateUser();
    $criteria = [];
    for ($i = 0; $i < 5; $i++) {
      $criteria[] = Criterion::create([
        'key' => sprintf('health.js.%02d', $i + 1),
        'sector' => 'health',
        'tier' => Criterion::TIER_FREE,
        'text' => 'Твердження js ' . $i,
        'status' => Criterion::STATUS_PUBLISHED,
        'origin' => Criterion::ORIGIN_SYSTEM,
        'in_formula' => TRUE,
        'weight' => $i,
        'uid' => $owner->id(),
      ]);
      $criteria[$i]->save();
    }
    $this->container->get(WheelCreation::class)->ensure($owner);
    $this->drupalLogin($owner);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $this->assertSession()->fieldNotExists('Не оцінено');

    $ids = array_map(static fn (Criterion $criterion): string => (string) $criterion->id(), $criteria);
    $encoded = json_encode($ids);
    $this->getSession()->executeScript(<<<JS
      for (const id of $encoded) {
        const radio = document.querySelector('input[name="criteria[' + id + '][answer]"][value="yes"]');
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
      }
JS);
    $this->assertSession()->elementNotExists('css', '[data-lifebalance-guide]');
    $this->assertSession()->elementNotExists('css', '[data-lifebalance-take]');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $saved = $this->assertSession()->waitForElement(
      'css',
      '[data-lifebalance-draft="saved"]',
    );
    $this->assertNotNull($saved);
    $this->assertSession()->pageTextNotContains('Збережено');

    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextNotContains('Орієнтир');
    $this->assertSession()->fieldNotExists('Не оцінено');
    $checked = $this->getSession()->evaluateScript('document.querySelectorAll(\'input[type="radio"]:checked\').length');
    $this->assertSame(5, $checked);
  }

  /**
   * Points of the score polygon, or an empty string when it is absent.
   */
  private function polygonPoints(): string {
    $points = $this->getSession()->evaluateScript(<<<'JS'
      return document.querySelector('svg.lifebalance-radar .lifebalance-radar__score')?.getAttribute('d') ?? '';
JS);
    $this->assertIsString($points);
    return $points;
  }

  /**
   * Number of snapshots owned by this account.
   */
  private function snapshotCount(UserInterface $account): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('lifebalance_snapshot')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $account->id())
      ->execute();
    return count($ids);
  }

}
