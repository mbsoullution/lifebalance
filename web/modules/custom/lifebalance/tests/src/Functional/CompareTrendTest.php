<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Comparison and trend stay on the owner's snapshots.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class CompareTrendTest extends BrowserTestBase {

  /**
   * Active sector IDs.
   *
   * @var list<string>
   */
  private const SECTORS = [
    'health',
    'relationships',
    'environment',
    'purpose',
    'finances',
    'self_development',
    'life_brightness',
    'spirituality',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lifebalance'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Next catalog key sequence.
   */
  private int $sequence = 0;

  /**
   * Deltas, historical answers, and incompatible guides.
   */
  public function testCompareAndTrend(): void {
    $this->drupalGet('/history/compare');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/history/trend');
    $this->assertSession()->addressMatches('#/user/login#');

    $owner = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();
    $stable = $this->addCriterion($owner, 'Спільна ревізія порівняння');
    $revised = $this->addCriterion($owner, 'Стара ревізія порівняння');
    $older = $this->saveSnapshot($owner, 6, 1700000000, [
      $this->answer($stable, 'yes'),
      $this->answer($revised, 'yes'),
    ]);

    $this->drupalLogin($owner);
    $this->drupalGet('/history');
    $this->assertSession()->linkExists('Порівняння');
    $this->drupalGet('/history/compare');
    $this->assertSession()->pageTextContains('Для порівняння потрібно щонайменше два зрізи.');
    $this->drupalGet('/history/trend');
    $this->assertSession()->pageTextContains('Для динаміки потрібно щонайменше два зрізи.');

    $this->drupalLogin($other);
    $this->drupalGet('/history/compare', ['query' => ['a' => $older->uuid()]]);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/history/trend');
    $this->assertSession()->pageTextNotContains('Спільна ревізія порівняння');

    $revised->set('text', 'Нова ревізія порівняння');
    $revised->save();
    $newer = $this->saveSnapshot($owner, 4, 1700001000, [
      $this->answer($stable, 'partial'),
      $this->answer($revised, 'no'),
    ]);

    $this->drupalLogin($owner);
    $this->drupalGet('/history/compare', [
      'query' => ['a' => $older->uuid(), 'b' => $newer->uuid()],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('−2');
    $this->assertSession()->pageTextContains('Спільна ревізія порівняння');
    $this->assertSession()->pageTextContains('Так');
    $this->assertSession()->pageTextContains('Частково');
    $this->assertSession()->pageTextNotContains('Стара ревізія порівняння');
    $this->assertSession()->pageTextNotContains('Нова ревізія порівняння');
    $this->assertSession()->pageTextContains('тому їх не зіставлено.');
    $this->assertSession()->pageTextNotContains('Орієнтир A');
    $this->assertSession()->elementExists('css', 'svg.lifebalance-radar rect');
    $this->assertSession()->elementExists('css', 'svg.lifebalance-radar circle');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $this->drupalLogin($other);
    $this->drupalGet('/history/compare', [
      'query' => ['b' => $newer->uuid()],
    ]);
    $this->assertSession()->statusCodeEquals(404);

    $this->setGuides($owner, $older, Snapshot::MODE_FREE, 20);
    $this->setGuides($owner, $newer, Snapshot::MODE_FREE, 50);
    $this->drupalLogin($owner);
    $this->drupalGet('/history/compare', [
      'query' => ['a' => $older->uuid(), 'b' => $newer->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Орієнтир A');
    $this->assertSession()->pageTextContains('+3');
    $this->assertSession()->pageTextNotContains('тому їх не зіставлено.');

    $this->setGuides($owner, $newer, Snapshot::MODE_PREMIUM, 90);
    $this->drupalGet('/history/compare', [
      'query' => ['a' => $older->uuid(), 'b' => $newer->uuid()],
    ]);
    $this->assertSession()->pageTextContains('Базовий');
    $this->assertSession()->pageTextContains('Розширений');
    $this->assertSession()->pageTextContains('Каталог 1');
    $this->assertSession()->pageTextContains('тому їх не зіставлено.');
    $this->assertSession()->pageTextNotContains('Орієнтир A');
    $this->assertSession()->pageTextContains('−2');

    $this->drupalGet('/history/trend', ['query' => ['sector' => 'health']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'svg.lifebalance-trend-chart polyline');
    $this->assertSession()->pageTextContains('6');
    $this->assertSession()->pageTextContains('4');
    $this->drupalGet('/history/trend', [
      'query' => ['sector' => 'health', 'period' => '90'],
    ]);
    $this->assertSession()->pageTextContains('У цьому періоді менше двох оцінок цього сектора.');
  }

  /**
   * Stores one snapshot. Health uses $health; every other sector is 4.
   *
   * @param \Drupal\user\UserInterface $owner
   *   Snapshot owner.
   * @param int $health
   *   Health score.
   * @param int $created
   *   UTC timestamp.
   * @param list<array<string, int|string>> $answers
   *   Answer items.
   */
  private function saveSnapshot(UserInterface $owner, int $health, int $created, array $answers): Snapshot {
    $this->container->get('current_user')->setAccount($owner);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => $sector === 'health' ? $health : 4,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'created' => $created,
      'scores' => $scores,
      'answers' => $answers,
    ]);
    $snapshot->save();
    return $snapshot;
  }

  /**
   * Sets the mode, formula, and health guide without touching the score.
   */
  private function setGuides(UserInterface $owner, Snapshot $snapshot, string $mode, int $guide): void {
    $this->container->get('current_user')->setAccount($owner);
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === 'health') {
        $item->set('guide', $guide);
      }
    }
    $snapshot->set('assessment_mode', $mode);
    $snapshot->set('formula_version', 'v1');
    $snapshot->save();
  }

  /**
   * Creates a published health criterion.
   */
  private function addCriterion(UserInterface $owner, string $text): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('health.compare.%02d', $this->sequence),
      'sector' => 'health',
      'tier' => Criterion::TIER_FREE,
      'text' => $text,
      'weight' => $this->sequence,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * One answer on the criterion's current revision.
   *
   * @return array{criterion_id: int, criterion_revision_id: int, answer: string}
   *   A field item.
   */
  private function answer(Criterion $criterion, string $answer): array {
    return [
      'criterion_id' => (int) $criterion->id(),
      'criterion_revision_id' => (int) $criterion->getRevisionId(),
      'answer' => $answer,
    ];
  }

}
