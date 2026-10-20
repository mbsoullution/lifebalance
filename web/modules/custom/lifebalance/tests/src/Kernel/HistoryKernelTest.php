<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\SnapshotHistory;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * History keeps the wording and guide that were stored with the snapshot.
 */
#[Group('lifebalance')]
final class HistoryKernelTest extends KernelTestBase {

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
    $this->createUser();
    $this->owner = $this->createUser();
    $this->owner->set('timezone', 'Europe/Kyiv');
    $this->owner->save();
    $this->setCurrentUser($this->owner);
    $this->grantEntitlement($this->owner);
  }

  /**
   * A later wording change does not rewrite the stored snapshot.
   */
  public function testHistoryKeepsTheAnsweredRevision(): void {
    $criteria = [];
    for ($i = 0; $i < 5; $i++) {
      $criteria[] = $this->addCriterion(Criterion::TIER_FREE, 'Старе формулювання історії');
    }
    $premium = $this->addCriterion(Criterion::TIER_PREMIUM, 'Поглиблене твердження історії');
    $extra_premium = [];
    for ($i = 0; $i < 4; $i++) {
      $extra_premium[] = $this->addCriterion(Criterion::TIER_PREMIUM, 'Додаткове розширене твердження');
    }
    $old_revision = (int) $criteria[0]->getRevisionId();
    $snapshot = $this->saveSnapshot(array_merge(
      $this->answerItems($criteria, 'yes'),
      $this->answerItems(array_merge([$premium], $extra_premium), 'yes'),
    ));
    $this->assertSame(100, $this->guide($snapshot));
    $this->assertSame(Snapshot::MODE_PREMIUM, $snapshot->getAssessmentMode());

    $criteria[0]->set('text', 'Нове формулювання історії');
    $criteria[0]->save();
    $this->assertNotSame($old_revision, (int) $criteria[0]->getRevisionId());

    $snapshot->set('note', 'Після зміни формулювання');
    $snapshot->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([(int) $snapshot->id()]);
    $reloaded = $storage->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $reloaded);
    $this->assertSame('Після зміни формулювання', $reloaded->getNote());
    $this->assertSame(100, $this->guide($reloaded));
    $this->assertSame(Snapshot::MODE_PREMIUM, $reloaded->getAssessmentMode());
    $stored_revision = NULL;
    foreach ($reloaded->getAnswers() as $item) {
      if ($item->getCriterionId() === (int) $criteria[0]->id()) {
        $stored_revision = $item->getCriterionRevisionId();
      }
    }
    $this->assertSame($old_revision, $stored_revision);

    $this->revokeEntitlements($this->owner);
    $rows = $this->container->get(SnapshotHistory::class)->answerRows($reloaded);
    $texts = array_column($rows, 'text');
    $this->assertContains('Старе формулювання історії', $texts);
    $this->assertNotContains('Нове формулювання історії', $texts);
    $this->assertContains('Поглиблене твердження історії', $texts);

    $reloaded->set('note', str_repeat('а', 1001));
    try {
      $reloaded->save();
      $this->fail('A long note was stored.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('1000', $exception->getMessage());
    }

    $health = NULL;
    foreach ($reloaded->getScores() as $item) {
      if ($item->getSectorId() === 'health') {
        $health = $item;
      }
    }
    $this->assertNotNull($health);
    $health->set('value', 9);
    try {
      $reloaded->save();
      $this->fail('A score change was stored.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('cannot be changed', $exception->getMessage());
    }
  }

  /**
   * Dates use the person's timezone, and the list page is twenty rows.
   */
  public function testTimezoneAndPagination(): void {
    $this->owner->set('timezone', 'Europe/Kyiv')->save();
    $this->setCurrentUser($this->owner);
    $snapshot = $this->saveSnapshot([]);
    $snapshot->set('created', 1700000000);
    $snapshot->save();
    $history = $this->container->get(SnapshotHistory::class);
    $expected = (new \DateTimeImmutable('@1700000000'))
      ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
      ->format('d.m.Y H:i');
    $this->assertSame($expected, $history->formatCreated($snapshot, $this->owner));
    $this->assertSame('4.0', $history->average($snapshot));
    $note = 'Початок ' . str_repeat('х', 90);
    $this->assertSame(mb_substr($note, 0, 79) . '…', $history->excerpt($note));

    for ($i = 0; $i < 20; $i++) {
      $extra = $this->saveSnapshot([]);
      $extra->set('created', 1700000001 + $i);
      $extra->save();
    }
    $repository = $this->container->get(SnapshotRepository::class);
    $uid = (int) $this->owner->id();
    $this->assertSame(21, $repository->countForOwner($uid));
    $first = $repository->loadPageForOwner($uid, 0, 20);
    $second = $repository->loadPageForOwner($uid, 1, 20);
    $this->assertCount(20, $first);
    $this->assertCount(1, $second);
    $this->assertSame(1700000000, $second[0]->getCreatedTime());
  }

  /**
   * A foreign delete URL can build breadcrumbs without revealing the snapshot.
   */
  public function testForeignDeleteBreadcrumbStaysQuiet(): void {
    $snapshot = $this->saveSnapshot([]);
    $snapshot->set('note', 'Таємна нотатка хлібних крихт');
    $snapshot->set('created', 1700000000);
    $snapshot->save();
    $uuid = (string) $snapshot->uuid();
    $request = Request::create('/history/' . $uuid . '/delete');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->container->get('router.request_context')->fromRequest($request);

    $other = $this->createUser();
    $this->setCurrentUser($other);
    $hidden = $this->breadcrumbTitles();
    $this->assertNotContains('Таємна нотатка хлібних крихт', $hidden);
    $date = (new \DateTimeImmutable('@1700000000'))
      ->setTimezone(new \DateTimeZone('Europe/Kyiv'))
      ->format('d.m.Y H:i');
    $this->assertNotContains($date, $hidden);

    $this->setCurrentUser($this->owner);
    $this->assertContains($date, $this->breadcrumbTitles());
  }

  /**
   * Visible breadcrumb labels for the current request path.
   *
   * @return list<string>
   *   Link labels.
   */
  private function breadcrumbTitles(): array {
    $breadcrumb = $this->container->get('breadcrumb')->build($this->container->get('current_route_match'));
    $titles = [];
    foreach ($breadcrumb->getLinks() as $link) {
      $titles[] = (string) $link->getText();
    }
    return $titles;
  }

  /**
   * Saves a snapshot whose every sector score is 4.
   *
   * @param list<array<string, int|string>> $answers
   *   Answer items.
   */
  private function saveSnapshot(array $answers): Snapshot {
    $scores = [];
    foreach ($this->sectors as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 4,
        'guide' => NULL,
        'answered' => 0,
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $this->owner->id(),
      'created' => 1700000000,
      'note' => 'Коротка нотатка',
      'scores' => $scores,
      'answers' => $answers,
    ]);
    $snapshot->save();
    return $snapshot;
  }

  /**
   * Creates a published health criterion.
   */
  private function addCriterion(string $tier, string $text): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('health.h%02d', $this->sequence),
      'sector' => 'health',
      'tier' => $tier,
      'text' => $text,
      'weight' => $this->sequence,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $this->owner->id(),
    ]);
    $criterion->save();
    return $criterion;
  }

  /**
   * Answer items for the current revision of each criterion.
   *
   * @param list<\Drupal\lifebalance\Entity\Criterion> $criteria
   *   Criteria to answer.
   * @param string $answer
   *   Stored answer: yes, partial, no, or na.
   *
   * @return list<array<string, int|string>>
   *   Field items.
   */
  private function answerItems(array $criteria, string $answer): array {
    $items = [];
    foreach ($criteria as $criterion) {
      $items[] = [
        'criterion_id' => (int) $criterion->id(),
        'criterion_revision_id' => (int) $criterion->getRevisionId(),
        'answer' => $answer,
      ];
    }
    return $items;
  }

  /**
   * Stored health guide.
   */
  private function guide(Snapshot $snapshot): ?int {
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === 'health') {
        return $item->getGuide();
      }
    }
    return NULL;
  }

}
