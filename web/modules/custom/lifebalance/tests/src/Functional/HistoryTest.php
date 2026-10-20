<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * History is private, historical, and does not edit scores or answers.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class HistoryTest extends BrowserTestBase {

  use EntitlementTestTrait;

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
   * The owner sees history. Another person receives 404.
   */
  public function testHistory(): void {
    $this->drupalGet('/history');
    $this->assertSession()->addressMatches('#/user/login#');

    $owner = $this->drupalCreateUser();
    $this->grantEntitlement($owner);
    $other = $this->drupalCreateUser();

    $free = $this->addCriterion($owner, Criterion::TIER_FREE, 'Старе формулювання історії');
    $premium = $this->addCriterion($owner, Criterion::TIER_PREMIUM, 'Поглиблене твердження історії');
    $note = 'Нотатка історії ' . str_repeat('х', 80);
    $this->container->get('current_user')->setAccount($owner);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 4,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => $note,
      'scores' => $scores,
      'answers' => [
        [
          'criterion_id' => (int) $free->id(),
          'criterion_revision_id' => (int) $free->getRevisionId(),
          'answer' => 'yes',
        ],
        [
          'criterion_id' => (int) $premium->id(),
          'criterion_revision_id' => (int) $premium->getRevisionId(),
          'answer' => 'partial',
        ],
      ],
    ]);
    $snapshot->save();
    $uuid = (string) $snapshot->uuid();

    $this->drupalGet('/history/' . $uuid);
    $this->assertSession()->addressMatches('#/user/login#');

    $this->drupalLogin($other);
    $this->drupalGet('/history');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Зрізів ще немає.');
    $this->assertSession()->pageTextNotContains('Нотатка історії');
    $this->drupalGet('/history/' . $uuid);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/history/' . $uuid . '/delete');
    $this->assertSession()->statusCodeEquals(404);

    $free->set('text', 'Нове формулювання історії');
    $free->save();
    $this->revokeEntitlements($owner);

    $this->drupalLogin($owner);
    $this->drupalGet('/history');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Середнє: 4.0');
    $this->assertSession()->pageTextContains('Розширений');
    $this->assertSession()->pageTextContains('Каталог 1');
    $this->assertSession()->pageTextContains('Нотатка історії');
    $this->assertSession()->pageTextNotContains($note);
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $this->drupalGet('/history/' . $uuid);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Старе формулювання історії');
    $this->assertSession()->pageTextNotContains('Нове формулювання історії');
    $this->assertSession()->pageTextContains('Поглиблене твердження історії');
    $this->assertSession()->pageTextContains('Розширене твердження');
    $this->assertSession()->pageTextContains('Частково');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $this->assertSession()->fieldValueEquals('Нотатка', $note);

    $updated = 'Оновлена нотатка історії';
    $this->submitForm(['Нотатка' => $updated], 'Зберегти нотатку');
    $this->assertSession()->pageTextContains('Нотатку збережено.');
    $this->assertSession()->fieldValueEquals('Нотатка', $updated);
    $this->assertSession()->pageTextContains('Старе формулювання історії');
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([(int) $snapshot->id()]);
    $reloaded = $storage->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $reloaded);
    $this->assertSame(4, $reloaded->getScores()[0]->getScore());
    $this->assertSame('partial', $reloaded->getAnswers()[1]->getAnswer());

    $this->drupalGet('/history/' . $uuid . '/delete');
    $this->submitForm([], 'Видалити');
    $this->assertSession()->pageTextContains('Зріз видалено.');
    $this->assertSession()->pageTextContains('Зрізів ще немає.');
    $this->assertNull($storage->load($snapshot->id()));
  }

  /**
   * Creates a published health criterion.
   */
  private function addCriterion(UserInterface $owner, string $tier, string $text): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('health.history.%02d', $this->sequence),
      'sector' => 'health',
      'tier' => $tier,
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

}
