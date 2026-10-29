<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Core\Database\StatementInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\social_auth\Entity\SocialAuth;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Export, account deletion, and the public privacy notice.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AccountExportTest extends BrowserTestBase {

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
    'social_auth',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A person downloads only their data, then deletion removes it.
   */
  public function testExportAndDeletion(): void {
    $this->drupalGet('/privacy');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Самооцінка в LifeBalance не є діагностикою');
    $this->assertSession()->pageTextContains('TODO для власника');
    $this->assertSession()->pageTextContains('Якщо ви просите Facebook видалити дані');
    $this->assertSession()->pageTextContains('Власник сайту може відкрити збережені колеса');
    $this->assertSession()->pageTextContains('Номер картки на цей сайт не передається');
    $this->assertSession()->addressMatches('#/privacy$#');
    $this->assertSession()->linkNotExists('Оферта');
    $this->drupalGet('/terms');
    $this->assertSession()->addressMatches('#/terms$#');
    $this->assertSession()->pageTextContains('Вона ще не є договором.');
    $this->assertSession()->pageTextContains('TODO для власника');
    $this->drupalGet('/refunds');
    $this->assertSession()->addressMatches('#/refunds$#');
    $this->assertSession()->linkNotExists('Оферта');
    $this->assertSession()->pageTextContains('Часткове повернення саме не завершує доступ.');
    $this->assertSession()->pageTextContains('TODO для власника');

    $this->drupalGet('/account/data');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/account/data/export/json');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/account/data/export/csv');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/account/data/delete');
    $this->assertSession()->addressMatches('#/user/login#');

    $owner = $this->drupalCreateUser();
    $this->grantEntitlement($owner);
    $other = $this->drupalCreateUser();

    $free = $this->addCriterion($owner, Criterion::TIER_FREE, 'Старе формулювання експорту');
    $premium = $this->addCriterion($owner, Criterion::TIER_PREMIUM, 'Поглиблене твердження експорту');
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
      'note' => '=нотатка експорту',
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
    $snapshot_id = (int) $snapshot->id();
    $free->set('text', 'Нове формулювання експорту');
    $free->save();
    $revision_id = (int) $free->getRevisionId();

    $this->container->get('current_user')->setAccount($other);
    $foreign = Snapshot::create([
      'uid' => $other->id(),
      'note' => 'Чужа нотатка експорту',
      'scores' => $scores,
    ]);
    $foreign->save();
    $foreign_id = (int) $foreign->id();

    $provider = SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_google',
      'provider_user_id' => 'google-export-owner',
      'token' => 'secret-token',
    ]);
    $provider->save();
    $provider_id = (int) $provider->id();

    $this->revokeEntitlements($owner);
    $this->container->get('user.data')->set('lifebalance', (int) $owner->id(), 'draft', [
      'scores' => [
        'health' => NULL,
        'relationships' => 0,
      ],
      'answers' => [
        (string) $premium->id() => [
          'rev' => (int) $premium->getRevisionId(),
          'a' => 'yes',
        ],
      ],
      'updated' => 1700000001,
      'note' => 'Чернетка експорту',
    ]);

    $this->drupalLogin($other);
    $this->drupalGet('/account/data/export/json');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Чужа нотатка експорту');
    $this->assertSession()->responseNotContains('=нотатка експорту');
    $this->assertSession()->responseNotContains('Чернетка експорту');
    $this->assertSession()->responseNotContains('Поглиблене твердження експорту');

    $this->drupalLogin($owner);
    $this->drupalGet('/account/data');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Завантажити JSON');
    $this->assertSession()->linkExists('Завантажити CSV');
    $this->assertSession()->linkExists('Видалити акаунт');
    $this->assertSession()->pageTextContains('Розширене оцінювання: недоступне');

    $this->drupalGet('/account/data/export/json');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'lifebalance.json');
    $this->assertSession()->responseContains('=нотатка експорту');
    $this->assertSession()->responseContains('Чернетка експорту');
    $this->assertSession()->responseContains($free->getCatalogKey());
    $this->assertSession()->responseContains('Старе формулювання експорту');
    $this->assertSession()->responseContains('Поглиблене твердження експорту');
    $this->assertSession()->responseNotContains('Нове формулювання експорту');
    $this->assertSession()->responseNotContains('Чужа нотатка експорту');
    $this->assertSession()->responseNotContains("'=нотатка експорту");
    $cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
    $stored = $this->container->get('user.data')->get('lifebalance', (int) $owner->id(), 'draft');
    $this->assertIsArray($stored);
    $this->assertSame('Чернетка експорту', $stored['note']);

    $this->drupalGet('/account/data/export/csv');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'lifebalance.csv');
    $this->assertSession()->responseContains("'=нотатка експорту");
    $this->assertSession()->responseContains($free->getCatalogKey());
    $this->assertSession()->responseNotContains('Чужа нотатка експорту');
    $csv_cache = (string) $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('no-store', $csv_cache);

    $this->drupalGet('/account/data/delete');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Видалити акаунт і всі збережені зрізи?');
    $this->assertSession()->pageTextNotContains('=нотатка експорту');
    $this->assertSession()->pageTextNotContains('Чернетка експорту');
    $this->assertSession()->pageTextNotContains('Чужа нотатка експорту');
    $this->submitForm([], 'Видалити акаунт');
    $this->assertSession()->pageTextContains('Акаунт видалено.');

    $snapshots = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $snapshots->resetCache();
    $this->assertNull($snapshots->load($snapshot_id));
    $this->assertSame(0, $this->countRows('lifebalance_snapshot', 'uid', (int) $owner->id()));
    $this->assertSame(0, $this->countRows('lifebalance_snapshot__answers', 'entity_id', $snapshot_id));
    $this->assertNull($this->container->get('user.data')->get('lifebalance', (int) $owner->id(), 'draft'));
    $this->assertNull(SocialAuth::load($provider_id));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('user')->load($owner->id()));

    $kept = $snapshots->load($foreign_id);
    $this->assertInstanceOf(Snapshot::class, $kept);
    $this->assertSame('Чужа нотатка експорту', $kept->getNote());
    $criteria = $this->container->get('entity_type.manager')->getStorage('lifebalance_criterion');
    $criteria->resetCache();
    $reloaded = $criteria->load((int) $free->id());
    $this->assertInstanceOf(Criterion::class, $reloaded);
    $this->assertSame(0, (int) $reloaded->getOwnerId());
    $this->assertSame($revision_id, (int) $reloaded->getRevisionId());
    $this->assertSame('Нове формулювання експорту', $reloaded->getText());
  }

  /**
   * Creates a published health criterion.
   */
  private function addCriterion(UserInterface $owner, string $tier, string $text): Criterion {
    $criterion = Criterion::create([
      'key' => 'health.http.' . $owner->id() . '.' . substr(hash('sha256', $text), 0, 8),
      'sector' => 'health',
      'tier' => $tier,
      'text' => $text,
      'weight' => 1,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Rows in one table matching a column.
   */
  private function countRows(string $table, string $field, int $value): int {
    $result = $this->container->get('database')->select($table, 't')
      ->condition($field, $value)
      ->countQuery()
      ->execute();
    $this->assertInstanceOf(StatementInterface::class, $result);
    return (int) $result->fetchField();
  }

}
