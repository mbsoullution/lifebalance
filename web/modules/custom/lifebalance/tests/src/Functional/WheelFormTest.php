<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Plugin\Field\FieldType\ScoreItem;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The wheel works from the form alone, without JavaScript.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class WheelFormTest extends BrowserTestBase {

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
   * An anonymous visitor is sent to login, and scores stay with the owner.
   */
  public function testWheel(): void {
    $this->drupalGet('/wheel');
    $this->assertStringContainsString('/user/login', $this->getUrl());

    $owner = $this->drupalCreateUser();
    $this->drupalLogin($owner);
    $this->drupalGet('/wheel');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->buttonExists('Створити моє колесо');
    $this->assertSession()->buttonNotExists('Зберегти зріз');
    $this->submitForm([], 'Створити моє колесо');
    $this->assertSession()->pageTextContains('Колесо створено.');
    $created = $this->container->get(WheelCreation::class)->createdAt($owner);
    $this->assertIsInt($created);
    $again = $this->container->get(WheelCreation::class)->ensure($owner);
    $this->assertSame($created, $again);
    $this->assertSession()->pageTextContains('зовсім не так, як хочу');
    $this->assertSession()->pageTextContains('саме так, як хочу');
    $this->assertSession()->buttonNotExists('Зберегти чернетку');
    $this->assertSession()->buttonExists('Зберегти результат');
    $this->assertSession()->linkExists('Оцінити');
    $this->assertSession()->elementTextEquals('css', '.lifebalance-columns__sector', 'Сфера');
    $this->assertSession()->elementTextEquals('css', '.lifebalance-columns__answers', 'Відповіді');
    $this->assertSession()->elementTextEquals('css', '.lifebalance-columns__score', 'Оцінка');
    $this->assertSession()->elementNotExists('css', 'table');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $this->assertSession()->fieldNotExists('Нотатка');
    $this->assertSession()->elementExists('css', 'svg.lifebalance-radar');
    $this->assertSession()->elementAttributeContains(
      'css',
      'a[href="#lifebalance-sector-health"]',
      'href',
      '#lifebalance-sector-health',
    );
    $this->assertSession()->elementExists('css', '#lifebalance-sector-health');
    $this->assertSession()->elementAttributeContains(
      'css',
      '#lifebalance-sector-health',
      'style',
      '--lifebalance-sector: #',
    );
    $this->submitForm([], 'Зберегти результат');
    $this->assertSession()->pageTextContains('потрібна оцінка кожного сектора');
    $this->assertSame(0, $this->snapshotCount($owner));

    $criteria = [];
    for ($i = 0; $i < 5; $i++) {
      $criteria[] = $this->addCriterion($owner);
    }
    $this->storeGuidedDraft($owner, $criteria, NULL);
    $this->drupalGet('/wheel');
    $this->assertNull($this->draftScore($owner, 'health'));
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-health a.lifebalance-assess',
      'Переоцінити',
    );
    $this->storeGuidedDraft($owner, $criteria);
    $this->drupalGet('/wheel');
    $this->assertSession()->pageTextNotContains('Орієнтир');
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-health .lifebalance-progress__value',
      '5/5',
    );
    $this->assertSession()->elementExists('css', 'svg.lifebalance-radar .lifebalance-radar__score');
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-health [data-lifebalance-score-value]',
      '10,0',
    );
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-purpose [data-lifebalance-score-value]',
      '—',
    );
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-health a.lifebalance-assess',
      'Переоцінити',
    );
    $this->assertSession()->elementTextEquals(
      'css',
      '#lifebalance-sector-purpose a.lifebalance-assess',
      'Оцінити',
    );
    $this->assertSession()->buttonNotExists('Взяти орієнтир (10)');
    $this->assertSame(3, $this->draftScore($owner, 'health'));
    $this->assertSame(0, $this->snapshotCount($owner));

    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[$sector] = $sector === 'health' ? 10 : 0;
    }
    $this->container->get(DraftStorage::class)->saveScores($owner, $scores, 'нотатка-лише-для-власника');
    $this->submitForm([], 'Зберегти результат');
    $this->assertSession()->pageTextContains('Зріз збережено.');
    $this->assertSession()->pageTextNotContains('Сьогодні вже є збережений зріз.');
    $snapshot = $this->onlySnapshot($owner);
    $this->assertSame(10, $this->scoreOn($snapshot, 'health'));
    $this->assertSame(100, $this->guideOn($snapshot, 'health'));
    $this->assertSame(0, $this->scoreOn($snapshot, 'purpose'));
    $this->assertSame('нотатка-лише-для-власника', $snapshot->getNote());

    $other = $this->drupalCreateUser();
    $this->container->get(WheelCreation::class)->ensure($other);
    $this->drupalLogin($other);
    $this->drupalGet('/wheel');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('Нотатка');
    $this->assertSession()->linkExists('Оцінити');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSame(10, $this->draftScore($owner, 'health'));

    $this->drupalLogin($owner);
    $this->drupalGet('/wheel');
    $flood = $this->container->get('flood');
    for ($i = 0; $i < 50; $i++) {
      $flood->register('lifebalance.snapshot', 86400, 'uid-' . $owner->id());
    }
    $this->submitForm([], 'Зберегти результат');
    $this->assertSession()->pageTextContains('максимальну кількість зрізів');
    $this->assertSame(1, $this->snapshotCount($owner));
  }

  /**
   * Puts a guide-ready draft in user.data.
   *
   * @param \Drupal\user\UserInterface $owner
   *   Account that owns the draft.
   * @param list<\Drupal\lifebalance\Entity\Criterion> $criteria
   *   Five published statements answered yes.
   * @param int|null $health
   *   Stored health score, or NULL when the person has not chosen one.
   */
  private function storeGuidedDraft(
    UserInterface $owner,
    array $criteria,
    ?int $health = 3,
  ): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[$sector] = NULL;
    }
    $storage = $this->container->get(DraftStorage::class);
    $storage->saveScores($owner, $scores, 'нотатка-лише-для-власника');
    if ($health !== NULL) {
      $scores['health'] = $health;
    }
    $answers = [];
    foreach ($criteria as $criterion) {
      $answers[(string) $criterion->id()] = [
        'rev' => (int) $criterion->getRevisionId(),
        'a' => 'yes',
      ];
    }
    $storage->store($owner, [
      'scores' => $scores,
      'answers' => $answers,
    ]);
  }

  /**
   * Creates one published free criterion in health.
   */
  private function addCriterion(UserInterface $owner): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('health.wheel.%02d', $this->sequence),
      'sector' => 'health',
      'tier' => Criterion::TIER_FREE,
      'text' => 'Твердження для колеса',
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Stored draft score for one sector.
   */
  private function draftScore(UserInterface $account, string $sector): ?int {
    $stored = $this->container->get('user.data')->get(
      DraftStorage::MODULE,
      (int) $account->id(),
      DraftStorage::NAME,
    );
    $this->assertIsArray($stored);
    $scores = $stored['scores'] ?? NULL;
    $this->assertIsArray($scores);
    $value = $scores[$sector] ?? NULL;
    return is_int($value) ? $value : NULL;
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

  /**
   * The only snapshot of this account.
   */
  private function onlySnapshot(UserInterface $account): Snapshot {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $account->id())
      ->execute();
    $loaded = $storage->load(reset($ids));
    $this->assertInstanceOf(Snapshot::class, $loaded);
    return $loaded;
  }

  /**
   * Person's score for one sector.
   */
  private function scoreOn(Snapshot $snapshot, string $sector): int {
    return $this->item($snapshot, $sector)->getScore();
  }

  /**
   * Stored guide for one sector.
   */
  private function guideOn(Snapshot $snapshot, string $sector): ?int {
    return $this->item($snapshot, $sector)->getGuide();
  }

  /**
   * Score item for one sector.
   */
  private function item(Snapshot $snapshot, string $sector): ScoreItem {
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === $sector) {
        return $item;
      }
    }
    throw new \RuntimeException('Missing sector score.');
  }

}
