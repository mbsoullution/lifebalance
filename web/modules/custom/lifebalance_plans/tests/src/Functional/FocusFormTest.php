<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Focus prompts stay private, limited, and unsaved.
 */
#[Group('lifebalance_plans')]
#[RunTestsInSeparateProcesses]
final class FocusFormTest extends BrowserTestBase {

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
  protected static $modules = [
    'lifebalance',
    'lifebalance_plans',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A person without Premium, then with it.
   */
  public function testFocusAndDemo(): void {
    $this->drupalGet('/plan/focus');
    $this->assertSession()->addressMatches('#/user/login#');

    $member = $this->drupalCreateUser();
    $other = $this->drupalCreateUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $publisher = $this->drupalCreateUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);

    $this->drupalLogin($member);
    $this->drupalGet('/plan/focus');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Щоб побачити підказки, спочатку дайте відповіді в опитувальнику.');
    $this->assertSession()->linkByHrefExists('/wheel');
    $this->assertSession()->pageTextNotContains('Перша демо-підказка');
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);

    $this->config('lifebalance_plans.settings')
      ->set('support_block', 'Текст блоку підтримки для тесту.')
      ->set('sensitive_criteria', ['relationships.p10'])
      ->save();
    $health = $this->addCriterion('health.f01', 'health');
    $sensitive = $this->addCriterion('relationships.p10', 'relationships');
    $this->publish($publisher, 'health.preview.first', 'health', 'health.f01', 'Перша демо-підказка', Recommendation::TIER_FREE_PREVIEW, Recommendation::SAFETY_NONE, 10);
    $this->publish($publisher, 'health.preview.second', 'health', 'health.f01', 'Друга демо-підказка', Recommendation::TIER_FREE_PREVIEW, Recommendation::SAFETY_NONE, 11);
    $this->publish($publisher, 'health.one', 'health', 'health.f01', 'Підказка один', Recommendation::TIER_PREMIUM, Recommendation::SAFETY_NONE, 1);
    $this->publish($publisher, 'health.two', 'health', 'health.f01', 'Підказка два', Recommendation::TIER_PREMIUM, Recommendation::SAFETY_HEALTH, 2);
    $this->publish($publisher, 'health.three', 'health', 'health.f01', 'Підказка три', Recommendation::TIER_PREMIUM, Recommendation::SAFETY_NONE, 3);
    $this->publish($publisher, 'health.four', 'health', 'health.f01', 'Підказка чотири', Recommendation::TIER_PREMIUM, Recommendation::SAFETY_NONE, 4);
    $this->publish($publisher, 'relationships.advice', 'relationships', 'relationships.p10', 'Порада якої не має бути', Recommendation::TIER_PREMIUM, Recommendation::SAFETY_NONE, 1);
    $this->storeSnapshot($member, $health, $sensitive);

    $this->drupalGet('/plan/focus');
    $this->assertSession()->pageTextContains('Підказки для саморефлексії на основі ваших відповідей.');
    $this->assertSession()->pageTextContains('Перша демо-підказка');
    $this->assertSession()->pageTextContains('У розширеному доступі — персональний план і кроки.');
    $this->assertSession()->pageTextContains('Текст блоку підтримки для тесту.');
    $this->assertSession()->pageTextNotContains('Друга демо-підказка');
    $this->assertSession()->pageTextNotContains('Підказка один');
    $this->assertSession()->pageTextNotContains('Порада якої не має бути');
    $this->assertSession()->pageTextNotContains('Це не медична порада.');
    $this->assertSession()->pageTextNotContains('Зберегти');
    $this->assertSession()->buttonNotExists('Додати до плану');
    $this->assertSession()->pageTextNotContains('діагноз');
    $this->assertSession()->pageTextNotContains('проблема');
    $this->assertSession()->pageTextNotContains('слабка');
    $this->assertSession()->pageTextNotContains('вам треба');

    $this->drupalLogin($other);
    $this->drupalGet('/plan/focus');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Щоб побачити підказки, спочатку дайте відповіді в опитувальнику.');
    $this->assertSession()->pageTextNotContains('Перша демо-підказка');
    $this->assertSession()->pageTextNotContains('Текст блоку підтримки для тесту.');

    $this->grantEntitlement($member);
    $this->drupalLogin($member);
    $this->drupalGet('/plan/focus');
    $this->assertSession()->pageTextContains('Найнижча оцінка в останньому зрізі.');
    $this->assertSession()->pageTextContains('Оберіть одну або дві сфери.');
    $this->assertSession()->pageTextNotContains('Перша демо-підказка');
    $this->submitForm([
      'sectors[health]' => 'health',
      'sectors[relationships]' => 'relationships',
    ], 'Показати підказки');
    $this->assertSession()->pageTextContains('Підказка один');
    $this->assertSession()->pageTextContains('Підказка два');
    $this->assertSession()->buttonExists('Додати до плану');
    $this->assertSession()->pageTextNotContains('Підказка три');
    $this->assertSession()->pageTextNotContains('Підказка чотири');
    $this->assertSession()->pageTextContains('Це не медична порада. Якщо симптоми турбують, зверніться до лікаря.');
    $this->assertSession()->pageTextContains('Текст блоку підтримки для тесту.');
    $this->assertSession()->pageTextNotContains('Порада якої не має бути');
    $this->assertSession()->pageTextContains('Для цієї сфери поки немає готових рекомендацій. Ви можете додати власну ціль.');
    $this->assertSession()->pageTextNotContains('Зберегти');
    $this->submitForm([
      'sectors[health]' => 'health',
      'sectors[relationships]' => 'relationships',
      'sectors[environment]' => 'environment',
    ], 'Показати підказки');
    $this->assertSession()->elementTextContains('css', '[role="alert"]', 'Оберіть одну або дві сфери.');
  }

  /**
   * Stores one published criterion.
   */
  private function addCriterion(string $key, string $sector): Criterion {
    $criterion = Criterion::create([
      'key' => $key,
      'sector' => $sector,
      'tier' => Criterion::TIER_FREE,
      'text' => 'Критерій ' . $key,
      'help' => '',
      'weight' => 1,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => 1,
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Stores one published recommendation as an editor.
   */
  private function publish(
    UserInterface $publisher,
    string $key,
    string $sector,
    string $trigger,
    string $title,
    string $tier,
    string $safety,
    int $weight,
  ): void {
    $this->container->get('current_user')->setAccount($publisher);
    Recommendation::create([
      'key' => $key,
      'sector' => $sector,
      'trigger_criteria' => [$trigger],
      'trigger_answers' => ['no', 'partial'],
      'title' => $title,
      'body' => [
        'value' => 'Можна спробувати помітити одне спостереження.',
        'format' => Recommendation::BODY_FORMAT,
      ],
      'steps' => ['Зробити один маленький крок'],
      'type' => Recommendation::TYPE_REFLECTION,
      'effort' => Recommendation::EFFORT_SMALL,
      'safety' => $safety,
      'tier' => $tier,
      'status' => Recommendation::STATUS_PUBLISHED,
      'weight' => $weight,
      'uid' => $publisher->id(),
    ])->save();
  }

  /**
   * Stores a snapshot whose lowest score is relationships.
   */
  private function storeSnapshot(UserInterface $member, Criterion $health, Criterion $sensitive): void {
    $this->container->get('current_user')->setAccount($member);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => $sector === 'relationships' ? 1 : 8,
      ];
    }
    $scores[0]['value'] = 2;
    Snapshot::create([
      'uid' => $member->id(),
      'note' => 'Нотатка фокусу',
      'scores' => $scores,
      'answers' => [
        [
          'criterion_id' => (int) $health->id(),
          'criterion_revision_id' => (int) $health->getRevisionId(),
          'answer' => 'no',
        ],
        [
          'criterion_id' => (int) $sensitive->id(),
          'criterion_revision_id' => (int) $sensitive->getRevisionId(),
          'answer' => 'no',
        ],
      ],
    ])->save();
  }

}
