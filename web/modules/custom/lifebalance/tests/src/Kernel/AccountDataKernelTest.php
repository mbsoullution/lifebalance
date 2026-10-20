<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Database\StatementInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\PersonalExport;
use Drupal\social_auth\Entity\SocialAuth;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Export stays with the owner, and account deletion removes that owner's rows.
 */
#[Group('lifebalance')]
final class AccountDataKernelTest extends KernelTestBase {

  use EntitlementTestTrait;
  use UserCreationTrait;

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
    'system',
    'user',
    'field',
    'text',
    'options',
    'social_api',
    'social_auth',
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installEntitySchema('social_auth');
    $this->installConfig(['lifebalance', 'social_auth']);
    // User 1 has every permission, so the people under test come after it.
    $this->createUser();
  }

  /**
   * The export keeps this person's revision text and stored draft.
   */
  public function testExportContainsOnlyThisAccount(): void {
    $owner = $this->createUser();
    $this->grantEntitlement($owner);
    $other = $this->createUser();

    $free = $this->addCriterion($owner, Criterion::TIER_FREE, 'Старе формулювання експорту');
    $premium = $this->addCriterion($owner, Criterion::TIER_PREMIUM, 'Поглиблене твердження експорту');
    $old_revision = (int) $free->getRevisionId();
    $this->setCurrentUser($owner);
    $owned = $this->saveSnapshot($owner, '=нотатка експорту', [
      $this->answerItem($free, 'yes'),
      $this->answerItem($premium, 'partial'),
    ]);
    $free->set('text', 'Нове формулювання експорту');
    $free->save();

    $this->setCurrentUser($other);
    $this->saveSnapshot($other, 'Чужа нотатка експорту', []);
    $this->container->get('user.data')->set('lifebalance', (int) $other->id(), 'draft', [
      'scores' => [],
      'answers' => [],
      'updated' => 1700000002,
      'note' => 'Чужа чернетка',
    ]);

    $this->revokeEntitlements($owner);
    $this->container->get('user.data')->set('lifebalance', (int) $owner->id(), 'draft', [
      'scores' => [
        'health' => NULL,
        'relationships' => 0,
      ],
      'answers' => [
        (string) $free->id() => [
          'rev' => 999999,
          'a' => 'no',
        ],
      ],
      'updated' => 1700000001,
      'note' => 'Чернетка експорту',
    ]);

    $this->setCurrentUser($owner);
    $export = $this->container->get(PersonalExport::class);
    $document = $export->document($owner);
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE);
    $this->assertIsString($encoded);
    $this->assertFalse($document['premium']);
    $this->assertCount(1, $document['snapshots']);
    $snapshot = $document['snapshots'][0];
    $this->assertSame((string) $owned->uuid(), $snapshot['uuid']);
    $this->assertSame('=нотатка експорту', $snapshot['note']);
    $this->assertSame(1, $snapshot['catalog_version']);
    $snapshot_answers = $snapshot['answers'];
    $this->assertIsArray($snapshot_answers);
    $texts = array_column($snapshot_answers, 'text');
    $this->assertContains('Старе формулювання експорту', $texts);
    $this->assertContains('Поглиблене твердження експорту', $texts);
    $this->assertNotContains('Нове формулювання експорту', $texts);
    $keys = array_column($snapshot_answers, 'key');
    $this->assertContains($free->getCatalogKey(), $keys);
    $draft = $document['draft'];
    $this->assertIsArray($draft);
    $this->assertSame('Чернетка експорту', $draft['note']);
    $this->assertSame([
      ['sector_id' => 'health', 'value' => NULL],
      ['sector_id' => 'relationships', 'value' => 0],
    ], $draft['scores']);
    $draft_answers = $draft['answers'];
    $this->assertIsArray($draft_answers);
    $first = $draft_answers[0];
    $this->assertIsArray($first);
    $this->assertSame($free->getCatalogKey(), $first['key']);
    $this->assertNull($first['text']);
    $this->assertSame(999999, $first['revision_id']);
    $this->assertStringNotContainsString('Чужа нотатка експорту', $encoded);
    $this->assertStringNotContainsString('Чужа чернетка', $encoded);
    $this->assertStringNotContainsString('Нове формулювання експорту', $encoded);

    $csv = $export->csv($owner);
    $this->assertStringContainsString("'=нотатка експорту", $csv);
    $this->assertStringContainsString($free->getCatalogKey(), $csv);
    $this->assertStringNotContainsString('Чужа нотатка експорту', $csv);

    $stored = $this->container->get('user.data')->get('lifebalance', (int) $owner->id(), 'draft');
    $this->assertIsArray($stored);
    $answers = $stored['answers'];
    $this->assertIsArray($answers);
    $stored_answer = $answers[(string) $free->id()];
    $this->assertIsArray($stored_answer);
    $this->assertSame(999999, $stored_answer['rev']);
    $this->assertSame($old_revision, $this->answeredRevision($owned, (int) $free->id()));
  }

  /**
   * Deleting the account removes its rows and keeps everyone else's.
   */
  public function testPredeleteRemovesPersonalData(): void {
    $owner = $this->createUser();
    $other = $this->createUser();
    $owner_id = (int) $owner->id();
    $criterion = $this->addCriterion($owner, Criterion::TIER_FREE, 'Критерій автора експорту');
    $other_criterion = $this->addCriterion($other, Criterion::TIER_FREE, 'Критерій іншої людини');
    $revision_id = (int) $criterion->getRevisionId();
    $this->setCurrentUser($owner);
    $snapshot = $this->saveSnapshot($owner, 'Нотатка видалення', [
      $this->answerItem($criterion, 'yes'),
    ]);
    $snapshot_id = (int) $snapshot->id();
    $this->setCurrentUser($other);
    $other_snapshot = $this->saveSnapshot($other, 'Чужа нотатка видалення', []);
    $other_snapshot_id = (int) $other_snapshot->id();

    $user_data = $this->container->get('user.data');
    $user_data->set('lifebalance', $owner_id, 'draft', [
      'scores' => ['health' => 3],
      'answers' => [],
      'updated' => 1700000003,
      'note' => 'Чернетка видалення',
    ]);
    $user_data->set('lifebalance', $owner_id, 'wheel_created', 1700000003);
    $user_data->set('lifebalance', (int) $other->id(), 'draft', [
      'scores' => [],
      'answers' => [],
      'updated' => 1700000004,
      'note' => 'Чужа чернетка видалення',
    ]);
    $provider = SocialAuth::create([
      'user_id' => $owner_id,
      'plugin_id' => 'social_auth_google',
      'provider_user_id' => 'google-owner-delete',
      'token' => 'secret-token',
    ]);
    $provider->save();
    $provider_id = (int) $provider->id();
    $other_provider = SocialAuth::create([
      'user_id' => $other->id(),
      'plugin_id' => 'social_auth_facebook',
      'provider_user_id' => 'facebook-other-delete',
      'token' => 'other-token',
    ]);
    $other_provider->save();
    $other_provider_id = (int) $other_provider->id();
    $entitlement = $this->grantEntitlement($owner);
    $entitlement_id = (int) $entitlement->id();
    $this->assertGreaterThan(0, $this->countRows('lifebalance_snapshot__answers', 'entity_id', $snapshot_id));

    $this->setCurrentUser($other);
    $owner->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache();
    $this->assertNull($storage->load($snapshot_id));
    $this->assertSame(0, $this->countRows('lifebalance_snapshot', 'uid', $owner_id));
    $this->assertSame(0, $this->countRows('lifebalance_snapshot__answers', 'entity_id', $snapshot_id));
    $this->assertNull($user_data->get('lifebalance', $owner_id, 'draft'));
    $this->assertNull($user_data->get('lifebalance', $owner_id, 'wheel_created'));
    $this->assertNull(SocialAuth::load($provider_id));
    $entitlements = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $entitlements->resetCache();
    $this->assertNull($entitlements->load($entitlement_id));
    $this->assertSame(0, $this->countRows('lifebalance_entitlement_log', 'target_uid', $owner_id));
    $this->assertGreaterThan(0, $this->countRows('lifebalance_entitlement_log', 'entitlement_id', $entitlement_id));

    $remaining = $storage->load($other_snapshot_id);
    $this->assertInstanceOf(Snapshot::class, $remaining);
    $this->assertSame('Чужа нотатка видалення', $remaining->getNote());
    $other_draft = $user_data->get('lifebalance', (int) $other->id(), 'draft');
    $this->assertIsArray($other_draft);
    $this->assertSame('Чужа чернетка видалення', $other_draft['note']);
    $this->assertNotNull(SocialAuth::load($other_provider_id));

    $criteria = $this->container->get('entity_type.manager')->getStorage('lifebalance_criterion');
    $criteria->resetCache();
    $reloaded = $criteria->load((int) $criterion->id());
    $this->assertInstanceOf(Criterion::class, $reloaded);
    $this->assertSame(0, (int) $reloaded->getOwnerId());
    $this->assertSame($revision_id, (int) $reloaded->getRevisionId());
    $this->assertSame('Критерій автора експорту', $reloaded->getText());
    $revisions = $criteria->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $criterion->id())
      ->execute();
    $this->assertCount(1, $revisions);
    $kept = $criteria->load((int) $other_criterion->id());
    $this->assertInstanceOf(Criterion::class, $kept);
    $this->assertSame((int) $other->id(), (int) $kept->getOwnerId());
  }

  /**
   * Creates a published criterion authored by this account.
   */
  private function addCriterion(UserInterface $author, string $tier, string $text): Criterion {
    $criterion = Criterion::create([
      'key' => 'health.export.' . $author->id() . '.' . substr(hash('sha256', $text), 0, 8),
      'sector' => 'health',
      'tier' => $tier,
      'text' => $text,
      'weight' => 1,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $author->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Saves a snapshot whose every sector score is 4.
   *
   * @param \Drupal\user\UserInterface $owner
   *   Snapshot owner. The current user must be this person.
   * @param string $note
   *   Stored note.
   * @param list<array<string, int|string>> $answers
   *   Answer items.
   */
  private function saveSnapshot(UserInterface $owner, string $note, array $answers): Snapshot {
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
      'answers' => $answers,
    ]);
    $snapshot->save();
    return $snapshot;
  }

  /**
   * One answer item for the criterion's current revision.
   *
   * @return array<string, int|string>
   *   Field item.
   */
  private function answerItem(Criterion $criterion, string $answer): array {
    return [
      'criterion_id' => (int) $criterion->id(),
      'criterion_revision_id' => (int) $criterion->getRevisionId(),
      'answer' => $answer,
    ];
  }

  /**
   * Revision id stored on the snapshot for this criterion.
   */
  private function answeredRevision(Snapshot $snapshot, int $criterion_id): ?int {
    foreach ($snapshot->getAnswers() as $item) {
      if ($item->getCriterionId() === $criterion_id) {
        return $item->getCriterionRevisionId();
      }
    }
    return NULL;
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
