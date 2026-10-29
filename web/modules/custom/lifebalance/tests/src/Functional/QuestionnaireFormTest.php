<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\lifebalance\Service\WheelCreation;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The sector questionnaire works from the form alone, without JavaScript.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class QuestionnaireFormTest extends BrowserTestBase {

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
   * Free criteria answer, premium stays blocked, and the guide is opt-in.
   */
  public function testQuestionnaire(): void {
    $this->drupalGet('/wheel/health');
    $this->assertStringContainsString('/user/login', $this->getUrl());
    $this->drupalGet('/premium');
    $this->assertSession()->statusCodeEquals(404);

    $owner = $this->drupalCreateUser();
    $this->drupalLogin($owner);
    $this->drupalGet('/wheel/not_a_sector');
    $this->assertSession()->statusCodeEquals(404);

    $free = [];
    for ($i = 0; $i < 10; $i++) {
      $free[] = $this->addCriterion($owner, 'free', $i, $i === 0);
    }
    $premium = [];
    for ($i = 0; $i < 10; $i++) {
      $premium[] = $this->addCriterion($owner, 'premium', $i, FALSE);
    }

    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[$sector] = NULL;
    }
    $this->container->get(DraftStorage::class)->saveScores($owner, $scores, 'нотатка-колеса');
    $this->container->get(WheelCreation::class)->ensure($owner);

    $this->drupalGet('/wheel/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->elementTextContains('css', 'legend', '1. Твердження free 0');
    $this->assertSession()->pageTextNotContains('Орієнтовно одна хвилина.');
    $this->assertSession()->pageTextNotContains('Не стосується');
    $this->assertSession()->pageTextNotContains('Так: 0');
    $this->assertSession()->elementNotExists('css', 'input[value="na"]');
    $this->assertSession()->elementExists('css', 'input[value="yes"]');
    $this->assertSession()->elementTextEquals('css', '[data-lifebalance-progress]', '0/10');
    $this->assertSession()->elementTextEquals('css', '[data-lifebalance-score-value]', '—');
    $this->assertSession()->pageTextNotContains('Замало відповідей для орієнтиру');
    $this->assertSession()->pageTextNotContains('зовсім не так, як хочу');
    $this->assertSession()->elementNotExists('css', 'input[type="range"]');
    $this->assertSession()->fieldNotExists('Не оцінено');
    $this->assertSame(
      ['Назад до колеса', 'Зберегти відповіді', 'Наступний сектор'],
      $this->actionLabels(),
    );
    $this->assertSession()->buttonExists('Назад до колеса');
    $this->assertSession()->buttonExists('Наступний сектор');
    $this->assertSession()->buttonExists('Зберегти відповіді');
    $this->assertSession()->buttonNotExists('Очистити');
    $this->assertSession()->pageTextNotContains('Твердження premium 0');
    $this->assertSession()->pageTextNotContains('поглиблених тверджень');
    $this->assertSession()->responseNotContains('<script>');
    $this->assertNull($this->getSession()->getPage()->find('css', 'input[type="radio"]:checked'));
    $radio = $this->assertSession()->elementExists('css', 'input[type="radio"]');
    $radioId = $radio->getAttribute('id');
    $this->assertIsString($radioId);
    $this->assertSession()->elementExists('css', 'label[for="' . $radioId . '"]');
    foreach ($free as $criterion) {
      $this->assertSession()->pageTextContains($criterion->getText());
    }

    $this->config('lifebalance.settings')->set('premium_enabled', TRUE)->save();
    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextNotContains('поглиблених тверджень');
    $this->assertSession()->pageTextNotContains('Твердження premium 0');

    $this->storeSnapshot($owner);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextContains('поглиблених тверджень');
    $this->assertSession()->linkByHrefExists('/premium');
    $this->assertSession()->pageTextNotContains('Твердження premium 0');
    $this->drupalGet('/premium');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Доступ поки за запрошенням.');
    $this->assertSession()->pageTextContains('10 тверджень у кожній із восьми сфер.');
    $this->assertSession()->pageTextContains('Ще 10 поглиблених тверджень у кожній сфері.');
    $this->assertSession()->pageTextNotContains('Купити');
    $this->assertSession()->pageTextNotContains('рекомендац');
    $this->assertSession()->pageTextNotContains('план на 30');
    $this->assertSession()->pageTextNotContains('оплат');
    $this->assertSession()->responseContains('Порівняння базового і розширеного оцінювання.');

    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextNotContains('поглиблених тверджень');
    $this->forgePremiumAnswer($free[0], $premium[0]);
    $answers = $this->storedAnswers($owner);
    $this->assertSame('yes', $answers[(string) $free[0]->id()]['a'] ?? NULL);
    $this->assertArrayNotHasKey((string) $premium[0]->id(), $answers);
    $this->assertSame('нотатка-колеса', $this->storedNote($owner));
    $this->assertSame(1, $this->snapshotCount($owner));

    $edit = [];
    foreach (array_slice($free, 0, 5) as $criterion) {
      $edit['criteria[' . $criterion->id() . '][answer]'] = 'yes';
    }
    $this->submitForm($edit, 'Зберегти відповіді');
    $this->assertSession()->pageTextContains('Відповіді збережено.');
    $this->assertSession()->pageTextNotContains('Орієнтир');
    $this->assertSession()->buttonNotExists('Взяти орієнтир (10)');
    $this->assertSession()->elementTextEquals('css', '[data-lifebalance-progress]', '5/10');
    $this->assertSession()->elementTextEquals('css', '[data-lifebalance-score-value]', '10,0');
    $this->assertSession()->pageTextNotContains('Замало відповідей для орієнтиру');
    $this->assertNull($this->draftScore($owner, 'health'));
    $this->assertSame(1, $this->snapshotCount($owner));

    $this->submitForm([], 'Наступний сектор');
    $this->assertStringContainsString('/wheel/relationships', $this->getUrl());
    $this->assertSame('yes', $this->storedAnswers($owner)[(string) $free[1]->id()]['a'] ?? NULL);

    $this->submitForm([], 'Назад до колеса');
    $this->assertStringContainsString('/wheel', $this->getUrl());
    $this->assertStringNotContainsString('/wheel/relationships', $this->getUrl());

    $this->drupalLogout();
    $this->drupalGet('/premium');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Доступ поки за запрошенням.');

    $member = $this->drupalCreateUser();
    $this->grantEntitlement($member);
    $this->container->get(WheelCreation::class)->ensure($member);
    $this->drupalLogin($member);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->pageTextContains('11. Твердження premium 0');
    $this->assertSession()->pageTextNotContains('Орієнтовно дві хвилини.');
    $this->assertSession()->elementTextEquals('css', '[data-lifebalance-progress]', '0/20');
    $this->assertSession()->pageTextNotContains('поглиблених тверджень');
    $this->submitForm([
      'criteria[' . $premium[0]->id() . '][answer]' => 'partial',
    ], 'Зберегти відповіді');
    $memberAnswers = $this->storedAnswers($member);
    $stored = $memberAnswers[(string) $premium[0]->id()] ?? NULL;
    $this->assertIsArray($stored);
    $this->assertSame('partial', $stored['a']);
    $this->assertSame((int) $premium[0]->getRevisionId(), $stored['rev']);
    $this->assertArrayNotHasKey((string) $premium[0]->id(), $this->storedAnswers($owner));

    $other = $this->drupalCreateUser();
    $this->container->get(WheelCreation::class)->ensure($other);
    $this->drupalLogin($other);
    $this->drupalGet('/wheel/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->pageTextNotContains('Орієнтир: 10');
    $this->assertNull($this->getSession()->getPage()->find('css', 'input[type="radio"]:checked'));
    $this->assertNull($this->draftScore($owner, 'health'));
  }

  /**
   * Visible action buttons, left to right.
   *
   * @return list<string>
   *   Button labels.
   */
  private function actionLabels(): array {
    $labels = [];
    $buttons = $this->getSession()->getPage()->findAll('css', '.form-actions input[type="submit"]');
    foreach ($buttons as $button) {
      $labels[] = (string) $button->getValue();
    }
    return $labels;
  }

  /**
   * Posts a premium answer that the form did not render.
   */
  private function forgePremiumAnswer(Criterion $free, Criterion $premium): void {
    $html = $this->getSession()->getPage()->getContent();
    preg_match('/name="form_build_id" value="([^"]+)"/', $html, $build);
    preg_match('/name="form_token" value="([^"]+)"/', $html, $token);
    preg_match('/name="form_id" value="([^"]+)"/', $html, $formId);
    $this->assertNotEmpty($build[1] ?? NULL);
    $this->assertNotEmpty($token[1] ?? NULL);
    $this->assertNotEmpty($formId[1] ?? NULL);
    $this->getHttpClient()->request('POST', $this->getUrl(), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'allow_redirects' => TRUE,
      'form_params' => [
        'form_build_id' => $build[1],
        'form_token' => $token[1],
        'form_id' => $formId[1],
        'criteria' => [
          $free->id() => ['answer' => 'yes'],
          $premium->id() => ['answer' => 'yes'],
        ],
        'save' => 'Зберегти відповіді',
      ],
    ]);
  }

  /**
   * Creates one published criterion in health.
   */
  private function addCriterion(UserInterface $owner, string $tier, int $weight, bool $withHelp): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('health.q.%s.%02d', $tier, $this->sequence),
      'sector' => 'health',
      'tier' => $tier,
      'text' => sprintf('Твердження %s %d', $tier, $weight),
      'help' => $withHelp ? 'підказка <script>x</script>' : '',
      'weight' => $weight,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Stores one snapshot so the premium teaser is allowed to appear.
   */
  private function storeSnapshot(UserInterface $owner): void {
    $this->container->get('current_user')->setAccount($owner);
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 0,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => '',
      'scores' => $scores,
    ]);
    $snapshot->save();
  }

  /**
   * Stored draft answers for this account.
   *
   * @return array<string, mixed>
   *   Answers keyed by criterion ID.
   */
  private function storedAnswers(UserInterface $account): array {
    $stored = $this->storedDraft($account);
    $answers = $stored['answers'] ?? NULL;
    $this->assertIsArray($answers);
    return $answers;
  }

  /**
   * Stored wheel note.
   */
  private function storedNote(UserInterface $account): string {
    $note = $this->storedDraft($account)['note'] ?? NULL;
    $this->assertIsString($note);
    return $note;
  }

  /**
   * Stored draft score for one sector.
   */
  private function draftScore(UserInterface $account, string $sector): ?int {
    $scores = $this->storedDraft($account)['scores'] ?? NULL;
    $this->assertIsArray($scores);
    $value = $scores[$sector] ?? NULL;
    return is_int($value) ? $value : NULL;
  }

  /**
   * Raw user.data draft.
   *
   * @return array<string, mixed>
   *   Stored draft.
   */
  private function storedDraft(UserInterface $account): array {
    $stored = $this->container->get('user.data')->get(
      DraftStorage::MODULE,
      (int) $account->id(),
      DraftStorage::NAME,
    );
    $this->assertIsArray($stored);
    return $stored;
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
