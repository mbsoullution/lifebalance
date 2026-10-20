<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Plugin\Field\FieldType\ScoreItem;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\CatalogVersion;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\GuideCalculator;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Snapshots: derived guide, entitlement, and owner-only access.
 */
#[Group('lifebalance')]
final class SnapshotKernelTest extends KernelTestBase {

  use EntitlementTestTrait;
  use UserCreationTrait;

  /**
   * Active sector IDs.
   *
   * @var list<string>
   */
  private array $sectors = [
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
   * Owner of the snapshots under test.
   */
  private UserInterface $owner;

  /**
   * Next catalog key sequence.
   */
  private int $sequence = 0;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installConfig(['lifebalance']);
    // The first account is user 1 and has every permission. Snapshots under
    // test belong to a later account so the entitlement check is real.
    $this->createUser();
    $this->owner = $this->createUser();
    $this->setCurrentUser($this->owner);
  }

  /**
   * The server replaces the guide and keeps a score of zero.
   */
  public function testServerRecalculatesGuideAndKeepsZero(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('lifebalance_snapshot'));
    $this->assertTrue($schema->tableExists('lifebalance_snapshot__scores'));
    $this->assertTrue($schema->tableExists('lifebalance_snapshot__answers'));
    $this->assertFalse($schema->tableExists('lifebalance_snapshot_revision'));
    $this->assertTrue($schema->indexExists('lifebalance_snapshot', 'snapshot__uid_created'));
    $indexed = $this->container->get('database')
      ->query('SHOW INDEX FROM {lifebalance_snapshot__answers}')
      ->fetchAll();
    $columns = array_map(static fn (object $row): string => (string) $row->Column_name, $indexed);
    $this->assertContains('answers_criterion_id', $columns);

    $empty = $this->reload($this->makeSnapshot($this->scores()));
    $this->assertSame(Snapshot::MODE_NONE, $empty->getAssessmentMode());
    $this->assertNull($empty->getFormulaVersion());
    $this->assertSame(1, $empty->getCatalogVersion());
    foreach ($empty->getScores() as $item) {
      $this->assertSame(0, $item->getScore());
      $this->assertNull($item->getGuide());
      $this->assertSame(0, $item->getAnswered());
    }

    $yes = [];
    for ($i = 0; $i < 5; $i++) {
      $yes[] = $this->addCriterion('health', Criterion::TIER_FREE);
    }
    $guided = $this->reload($this->makeSnapshot($this->scores(), $this->answerItems($yes, 'yes')));
    $this->assertSame(Snapshot::MODE_FREE, $guided->getAssessmentMode());
    $this->assertSame(GuideCalculator::VERSION, $guided->getFormulaVersion());
    $this->assertSame(100, $this->scoreFor($guided, 'health')->getGuide());
    $this->assertSame(5, $this->scoreFor($guided, 'health')->getAnswered());
    $this->assertNull($this->scoreFor($guided, 'purpose')->getGuide());
    $this->assertSame(0, $this->scoreFor($guided, 'purpose')->getScore());

    $few = [];
    for ($i = 0; $i < 4; $i++) {
      $few[] = $this->addCriterion('relationships', Criterion::TIER_FREE);
    }
    $below = $this->reload($this->makeSnapshot($this->scores(), $this->answerItems($few, 'yes')));
    $this->assertSame(Snapshot::MODE_FREE, $below->getAssessmentMode());
    $this->assertNull($below->getFormulaVersion());
    $this->assertNull($this->scoreFor($below, 'relationships')->getGuide());
    $this->assertSame(4, $this->scoreFor($below, 'relationships')->getAnswered());

    $na = [];
    for ($i = 0; $i < 5; $i++) {
      $na[] = $this->addCriterion('environment', Criterion::TIER_FREE);
    }
    $ignored = $this->reload($this->makeSnapshot($this->scores(), $this->answerItems($na, 'na')));
    $this->assertSame(Snapshot::MODE_FREE, $ignored->getAssessmentMode());
    $this->assertNull($this->scoreFor($ignored, 'environment')->getGuide());
    $this->assertSame(0, $this->scoreFor($ignored, 'environment')->getAnswered());

    $no = [];
    for ($i = 0; $i < 5; $i++) {
      $no[] = $this->addCriterion('purpose', Criterion::TIER_FREE);
    }
    $zeros = $this->reload($this->makeSnapshot($this->scores(), $this->answerItems($no, 'no')));
    $this->assertSame(0, $this->scoreFor($zeros, 'purpose')->getGuide());
    $this->assertSame(5, $this->scoreFor($zeros, 'purpose')->getAnswered());

    $counted = [];
    $skipped = [];
    for ($i = 0; $i < 5; $i++) {
      $counted[] = $this->addCriterion('finances', Criterion::TIER_FREE, TRUE);
      $skipped[] = $this->addCriterion('finances', Criterion::TIER_FREE, FALSE);
    }
    $formula = $this->reload($this->makeSnapshot($this->scores(), array_merge(
      $this->answerItems($counted, 'yes'),
      $this->answerItems($skipped, 'no'),
    )));
    $this->assertSame(100, $this->scoreFor($formula, 'finances')->getGuide());
    $this->assertSame(5, $this->scoreFor($formula, 'finances')->getAnswered());

    $noted = $this->makeSnapshot($this->scores(), [], str_repeat('а', 1000));
    $noted = $this->reload($noted);
    $this->assertSame(1000, mb_strlen($noted->getNote()));
    $this->assertSaveFails(
      $this->makeSnapshot($this->scores(), [], str_repeat('а', 1001)),
      '1000',
    );

    $noted->set('note', 'Оновлена нотатка');
    $noted = $this->reload($noted);
    $this->assertSame('Оновлена нотатка', $noted->getNote());
    $this->assertSame(0, $this->scoreFor($noted, 'health')->getScore());
    $health = $this->scoreFor($noted, 'health');
    $health->set('value', 4);
    $this->assertSaveFails($noted, 'cannot be changed');
    $reloaded = $this->repository()->loadByUuidForOwner((string) $noted->uuid(), (int) $this->owner->id());
    $this->assertInstanceOf(Snapshot::class, $reloaded);
    $this->assertSame('Оновлена нотатка', $reloaded->getNote());
    $this->assertSame(0, $this->scoreFor($reloaded, 'health')->getScore());

    $health_sector = Sector::load('health');
    $this->assertInstanceOf(Sector::class, $health_sector);
    $health_sector->setStatus(FALSE)->save();
    $without = $this->reload($this->makeSnapshot($this->scores(omit: ['health'])));
    $this->assertCount(7, $without->getScores());
    $this->assertSaveFails(
      $this->makeSnapshot($this->scores()),
      'вимкнений сектор',
    );
  }

  /**
   * Incomplete scores and stale answers are not stored.
   */
  public function testRejectedSnapshotsAreNotStored(): void {
    $uid = (int) $this->owner->id();
    $this->assertSaveFails($this->makeSnapshot($this->scores(omit: ['health'])), 'Кожен активний сектор');
    $this->assertSaveFails($this->makeSnapshot($this->scores(values: ['health' => 11])), '0 до 10');
    $duplicate = $this->scores();
    $duplicate[] = [
      'sector_id' => 'health',
      'value' => 3,
      'guide' => 99,
      'answered' => 1,
    ];
    $this->assertSaveFails($this->makeSnapshot($duplicate), 'повторюється');
    $unknown = $this->scores();
    $unknown[] = [
      'sector_id' => 'nope',
      'value' => 3,
      'guide' => NULL,
      'answered' => 0,
    ];
    $this->assertSaveFails($this->makeSnapshot($unknown), 'невідомий або вимкнений');

    $archived = $this->addCriterion('health', Criterion::TIER_FREE);
    $archived->set('status', Criterion::STATUS_ARCHIVED)->save();
    $this->assertSaveFails(
      $this->makeSnapshot($this->scores(), $this->answerItems([$archived], 'yes')),
      'поточною ревізією',
    );

    $draft = $this->addCriterion('health', Criterion::TIER_FREE, TRUE, Criterion::STATUS_DRAFT);
    $this->assertSaveFails(
      $this->makeSnapshot($this->scores(), $this->answerItems([$draft], 'yes')),
      'поточною ревізією',
    );

    $current = $this->addCriterion('health', Criterion::TIER_FREE);
    $old_revision = (int) $current->getRevisionId();
    $current->set('text', 'Нове формулювання для нової ревізії')->save();
    $this->assertNotSame($old_revision, (int) $current->getRevisionId());
    $this->assertSaveFails(
      $this->makeSnapshot($this->scores(), $this->answerItems([$current], 'yes', $old_revision)),
      'поточною ревізією',
    );
    $this->assertCount(0, $this->repository()->loadForOwner($uid));
  }

  /**
   * The catalog version is taken from the file and kept when the note changes.
   */
  public function testCatalogVersionIsServerOwned(): void {
    $catalog = $this->container->get(CatalogVersion::class);
    $version = $catalog->current();
    $this->assertSame(1, $version);

    $submitted = $this->makeSnapshot($this->scores());
    $submitted->set('catalog_version', 99);
    $saved = $this->reload($submitted);
    $this->assertSame($version, $saved->getCatalogVersion());

    $saved->set('catalog_version', 2);
    $saved->set('note', 'Нотатка не змінює версію каталогу');
    $kept = $this->reload($saved);
    $this->assertSame(2, $kept->getCatalogVersion());
    $this->assertSame('Нотатка не змінює версію каталогу', $kept->getNote());
  }

  /**
   * A premium answer is stored only when the entitlement check passes.
   */
  public function testPremiumAnswersFollowEntitlement(): void {
    $free = [];
    $premium = [];
    for ($i = 0; $i < 5; $i++) {
      $free[] = $this->addCriterion('health', Criterion::TIER_FREE);
      $premium[] = $this->addCriterion('health', Criterion::TIER_PREMIUM);
    }
    $answers = array_merge(
      $this->answerItems($free, 'yes'),
      $this->answerItems($premium, 'no'),
    );
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $meta = $checker->getCacheability($this->owner);
    $this->assertSame(['user'], $meta->getCacheContexts());
    $tag = 'lifebalance_entitlement:' . $this->owner->id();
    $this->assertContains($tag, $meta->getCacheTags());
    $this->assertFalse($checker->hasPremium($this->owner));

    $this->assertSaveFails($this->makeSnapshot($this->scores(), $answers), 'розширеного оцінювання');

    $this->grantEntitlement($this->owner);
    $this->assertTrue($checker->hasPremium($this->owner));
    $saved = $this->reload($this->makeSnapshot($this->scores(), $answers));
    $this->assertSame(Snapshot::MODE_PREMIUM, $saved->getAssessmentMode());
    $this->assertSame(GuideCalculator::VERSION, $saved->getFormulaVersion());
    $this->assertSame(50, $this->scoreFor($saved, 'health')->getGuide());
    $this->assertSame(10, $this->scoreFor($saved, 'health')->getAnswered());

    $site_manager = Role::load('site_manager');
    $this->assertInstanceOf(Role::class, $site_manager);
    $this->assertFalse($site_manager->hasPermission('administer lifebalance snapshots'));
    $this->assertFalse($site_manager->hasPermission('administer lifebalance entitlements'));
    $this->revokeEntitlements($this->owner);
    $this->assertFalse($checker->hasPremium($this->owner));
  }

  /**
   * Another person, including a site manager, cannot see or delete a snapshot.
   */
  public function testOnlyTheOwnerCanViewOrDelete(): void {
    $checksums = $this->container->get('cache_tags.invalidator.checksum');
    $tag = 'lifebalance_snapshot_list:' . $this->owner->id();
    $before = $checksums->getCurrentChecksum([$tag]);
    $older = $this->reload($this->makeSnapshot($this->scores(), [], '', 1_700_000_100));
    $newer = $this->reload($this->makeSnapshot($this->scores(), [], '', 1_700_000_200));
    $same_time = $this->reload($this->makeSnapshot($this->scores(), [], '', 1_700_000_200));
    $after = $checksums->getCurrentChecksum([$tag]);
    $this->assertNotSame($before, $after);

    $loaded = $this->repository()->loadForOwner((int) $this->owner->id());
    $this->assertSame(
      [(int) $same_time->id(), (int) $newer->id(), (int) $older->id()],
      array_map(static fn (Snapshot $snapshot): int => (int) $snapshot->id(), $loaded),
    );
    $this->assertSame(
      (int) $older->id(),
      (int) $this->repository()->loadByUuidForOwner((string) $older->uuid(), (int) $this->owner->id())?->id(),
    );

    $other = $this->createUser();
    $this->assertNull($this->repository()->loadByUuidForOwner((string) $older->uuid(), (int) $other->id()));
    $this->assertCount(0, $this->repository()->loadForOwner((int) $other->id()));
    $this->expectOwnerRequired();

    $manager = $this->createUser();
    $manager->addRole('site_manager');
    $manager->save();
    $privileged = $this->createUser(['administer lifebalance snapshots']);
    $anonymous = new AnonymousUserSession();

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('lifebalance_snapshot');
    $this->assertTrue($older->access('view', $this->owner));
    $this->assertTrue($older->access('update', $this->owner));
    $this->assertTrue($older->access('delete', $this->owner));
    $this->assertTrue($handler->createAccess(NULL, $this->owner));
    foreach ([$other, $manager, $privileged, $anonymous] as $account) {
      $result = $older->access('view', $account, TRUE);
      if (!$result instanceof AccessResult) {
        throw new \RuntimeException('Access result is missing.');
      }
      $this->assertFalse($result->isAllowed());
      $this->assertContains('user', $result->getCacheContexts());
      $this->assertFalse($older->access('update', $account));
      $this->assertFalse($older->access('delete', $account));
    }
    $this->assertFalse($handler->createAccess(NULL, $anonymous));

    $older->delete();
    $this->assertNull($this->repository()->loadByUuidForOwner((string) $older->uuid(), (int) $this->owner->id()));
  }

  /**
   * Builds scores for every active sector unless one is omitted.
   *
   * @param array<string, int> $values
   *   Score overrides. Missing sectors use 0.
   * @param list<string> $omit
   *   Sector IDs to leave out.
   *
   * @return list<array<string, int|string|null>>
   *   Field items, including a client guide the server must replace.
   */
  private function scores(array $values = [], array $omit = []): array {
    $items = [];
    foreach ($this->sectors as $sector) {
      if (in_array($sector, $omit, TRUE)) {
        continue;
      }
      $items[] = [
        'sector_id' => $sector,
        'value' => $values[$sector] ?? 0,
        'guide' => 99,
        'answered' => 7,
      ];
    }
    return $items;
  }

  /**
   * Creates an unsaved snapshot with client-supplied derived fields.
   *
   * @param list<array<string, int|string|null>> $scores
   *   Score items.
   * @param list<array<string, int|string>> $answers
   *   Answer items.
   * @param string $note
   *   Private note.
   * @param int $created
   *   UTC timestamp.
   *
   * @return \Drupal\lifebalance\Entity\Snapshot
   *   Unsaved snapshot.
   */
  private function makeSnapshot(array $scores, array $answers = [], string $note = '', int $created = 1_700_000_000): Snapshot {
    $snapshot = Snapshot::create([
      'uid' => $this->owner->id(),
      'created' => $created,
      'note' => $note,
      'assessment_mode' => Snapshot::MODE_PREMIUM,
      'formula_version' => 'v9',
      'scores' => $scores,
      'answers' => $answers,
    ]);
    return $snapshot;
  }

  /**
   * Saves and reloads so the assertions see the stored row.
   */
  private function reload(Snapshot $snapshot): Snapshot {
    $snapshot->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([(int) $snapshot->id()]);
    $loaded = $storage->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $loaded);
    return $loaded;
  }

  /**
   * Creates a criterion owned by the snapshot owner.
   */
  private function addCriterion(string $sector, string $tier, bool $in_formula = TRUE, string $status = Criterion::STATUS_PUBLISHED): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('%s.t%02d', $sector, $this->sequence),
      'sector' => $sector,
      'tier' => $tier,
      'text' => 'Твердження для перевірки',
      'weight' => $this->sequence,
      'status' => $status,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => $in_formula,
      'uid' => $this->owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Answer items for the given criteria.
   *
   * @param list<\Drupal\lifebalance\Entity\Criterion> $criteria
   *   Criteria to answer.
   * @param string $answer
   *   Stored answer: yes, partial, no, or na.
   * @param int|null $revision_id
   *   Revision to store. The current revision is used when this is omitted.
   *
   * @return list<array<string, int|string>>
   *   Field items.
   */
  private function answerItems(array $criteria, string $answer, ?int $revision_id = NULL): array {
    $items = [];
    foreach ($criteria as $criterion) {
      $items[] = [
        'criterion_id' => (int) $criterion->id(),
        'criterion_revision_id' => $revision_id ?? (int) $criterion->getRevisionId(),
        'answer' => $answer,
      ];
    }
    return $items;
  }

  /**
   * Score item for one sector.
   */
  private function scoreFor(Snapshot $snapshot, string $sector): ScoreItem {
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === $sector) {
        return $item;
      }
    }
    throw new \RuntimeException($sector);
  }

  /**
   * The snapshot must not be stored.
   */
  private function assertSaveFails(Snapshot $snapshot, string $message): void {
    try {
      $snapshot->save();
      $this->fail('The snapshot must not be stored.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString($message, $exception->getMessage());
    }
  }

  /**
   * Snapshot queries without an owner are refused.
   */
  private function expectOwnerRequired(): void {
    try {
      $this->repository()->loadForOwner(0);
      $this->fail('A snapshot query without an owner must be refused.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('owner', $exception->getMessage());
    }
  }

  /**
   * Snapshot repository.
   */
  private function repository(): SnapshotRepository {
    return $this->container->get(SnapshotRepository::class);
  }

}
