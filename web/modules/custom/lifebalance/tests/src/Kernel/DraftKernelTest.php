<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Controller\DraftController;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\DraftRejected;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\Tests\lifebalance\EntitlementTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drafts live in user.data and never rewrite a snapshot.
 */
#[Group('lifebalance')]
final class DraftKernelTest extends KernelTestBase {

  use EntitlementTestTrait;
  use UserCreationTrait;

  /**
   * Active sector IDs, in weight order.
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
   * Owner of the draft under test.
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
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installConfig(['lifebalance']);
    // The first account is user 1 and has every permission.
    $this->createUser();
    $this->owner = $this->createUser();
    $this->setCurrentUser($this->owner);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A new person gets null scores. A snapshot seeds the next draft.
   */
  public function testInitialization(): void {
    $empty = $this->drafts()->load($this->owner);
    $this->assertSame(self::SECTORS, array_keys($empty->scores));
    foreach ($empty->scores as $score) {
      $this->assertNull($score);
    }
    $this->assertSame([], $empty->answers);
    $this->assertSame([], $empty->outdated);
    $this->assertSame(0, $empty->updated);
    $this->assertNull($this->stored($this->owner));

    $free = $this->addCriterion('health', Criterion::TIER_FREE);
    $stale = $this->addCriterion('purpose', Criterion::TIER_FREE);
    $archived = $this->addCriterion('finances', Criterion::TIER_FREE);
    $previous = (int) $stale->getRevisionId();
    $first = $this->saveSnapshot($this->scores([
      'health' => 0,
      'purpose' => 4,
    ]), array_merge(
      $this->answerItems([$free], 'yes'),
      $this->answerItems([$stale], 'partial'),
      $this->answerItems([$archived], 'no'),
    ), 100);
    $stale->set('text', 'Нове формулювання');
    $stale->save();
    $archived->set('status', Criterion::STATUS_ARCHIVED);
    $archived->save();

    $seeded = $this->drafts()->load($this->owner);
    $this->assertSame(0, $seeded->scores['health']);
    $this->assertSame(4, $seeded->scores['purpose']);
    $this->assertSame(0, $seeded->scores['relationships']);
    $this->assertSame([
      'rev' => (int) $free->getRevisionId(),
      'a' => 'yes',
    ], $seeded->answers[(int) $free->id()]);
    $this->assertArrayNotHasKey((int) $stale->id(), $seeded->answers);
    $this->assertArrayNotHasKey((int) $archived->id(), $seeded->answers);
    $expectedOutdated = [(int) $archived->id(), (int) $stale->id()];
    sort($expectedOutdated);
    $this->assertSame($expectedOutdated, $seeded->outdated);
    $this->assertSame(0, $seeded->updated);
    $this->assertNull($this->stored($this->owner));
    $this->assertSame('partial', $this->answerOn($first, (int) $stale->id()));
    $this->assertSame($previous, $this->revisionOn($first, (int) $stale->id()));
    $this->assertNotSame($previous, (int) $stale->getRevisionId());

    $second = $this->saveSnapshot($this->scores(['health' => 2]), [], 200);
    $third = $this->saveSnapshot($this->scores(['health' => 5]), [], 200);
    $this->assertGreaterThan((int) $second->id(), (int) $third->id());
    $latest = $this->drafts()->load($this->owner);
    $this->assertSame(5, $latest->scores['health']);

    $stored = $this->drafts()->store($this->owner, $this->payload([
      'health' => 7,
      'relationships' => NULL,
    ]));
    $this->assertSame(7, $stored->scores['health']);
    $this->assertNull($stored->scores['relationships']);
    $this->assertSame([], $stored->outdated);
    $this->assertGreaterThan(0, $stored->updated);
    $this->saveSnapshot($this->scores(['health' => 1]), [], 300);
    $kept = $this->drafts()->load($this->owner);
    $this->assertSame(7, $kept->scores['health']);

    $this->drafts()->clear($this->owner);
    $reseeded = $this->drafts()->load($this->owner);
    $this->assertSame(1, $reseeded->scores['health']);
    $this->assertNull($this->stored($this->owner));
  }

  /**
   * Invalid and premium bodies are refused and leave the draft untouched.
   */
  public function testRejection(): void {
    $saved = $this->drafts()->store($this->owner, $this->payload([
      'health' => NULL,
    ]));
    $this->assertNull($saved->scores['health']);
    $before = $this->stored($this->owner);
    $free = $this->addCriterion('health', Criterion::TIER_FREE);
    $archived = $this->addCriterion('health', Criterion::TIER_FREE, Criterion::STATUS_ARCHIVED);
    $revision = (int) $free->getRevisionId();

    $invalid = [
      ['scores' => $this->nullScores()],
      ['answers' => new \stdClass()],
      $this->payload() + ['guide' => 10],
      $this->withScore('missing', 1),
      $this->withScore('health', 11),
      $this->withScore('health', -1),
      $this->withScore('health', 1.5),
      $this->withScore('health', '5'),
      $this->withScore('health', TRUE),
      $this->withAnswer('999999', 1, 'yes'),
      $this->withAnswer((string) $free->id(), $revision, 'maybe'),
      $this->withAnswer((string) $free->id(), $revision, 'YES'),
      $this->withAnswer((string) $free->id(), $revision + 99, 'yes'),
      $this->withScore('nope', 1),
      $this->withAnswer((string) $archived->id(), (int) $archived->getRevisionId(), 'no'),
      $this->withAnswerShape((string) $free->id(), [
        'rev' => $revision,
        'a' => 'yes',
        'guide' => 9,
      ]),
    ];
    foreach ($invalid as $payload) {
      $this->assertRejected($payload, DraftRejected::INVALID);
    }
    $this->assertEquals($before, $this->stored($this->owner));

    $premium = $this->addCriterion('spirituality', Criterion::TIER_PREMIUM);
    $premiumBody = $this->withAnswer(
      (string) $premium->id(),
      (int) $premium->getRevisionId(),
      'yes',
    );
    $this->assertRejected($premiumBody, DraftRejected::FORBIDDEN);
    $this->assertEquals($before, $this->stored($this->owner));

    $denied = $this->post(json_encode($premiumBody, JSON_THROW_ON_ERROR));
    $this->assertSame(DraftRejected::FORBIDDEN, $denied->getStatusCode());
    $this->assertSame('{}', $denied->getContent());
    $this->assertEquals($before, $this->stored($this->owner));

    $this->grantPremium();
    $accepted = $this->drafts()->store($this->owner, $premiumBody);
    $this->assertSame('yes', $accepted->answers[(int) $premium->id()]['a']);

    $snapshot = $this->saveSnapshot($this->scores(), $this->answerItems([$premium], 'partial'), 400);
    $this->assertSame(Snapshot::MODE_PREMIUM, $snapshot->getAssessmentMode());
    $this->revokePremium();
    $this->drafts()->clear($this->owner);
    $without = $this->drafts()->load($this->owner);
    $this->assertArrayNotHasKey((int) $premium->id(), $without->answers);
    $this->assertSame([], $without->outdated);
    $reloaded = $this->reloadSnapshot($snapshot);
    $this->assertSame(Snapshot::MODE_PREMIUM, $reloaded->getAssessmentMode());
    $this->assertSame('partial', $this->answerOn($reloaded, (int) $premium->id()));

    $this->grantPremium();
    $this->drafts()->store($this->owner, $premiumBody);
    $this->revokePremium();
    $stripped = $this->drafts()->load($this->owner);
    $this->assertArrayNotHasKey((int) $premium->id(), $stripped->answers);
    $raw = $this->stored($this->owner);
    $this->assertIsArray($raw);
    $this->assertIsArray($raw['answers']);
    $this->assertArrayNotHasKey((string) $premium->id(), $raw['answers']);
    $still = $this->reloadSnapshot($snapshot);
    $this->assertSame('partial', $this->answerOn($still, (int) $premium->id()));
    $this->assertSame(Snapshot::MODE_PREMIUM, $still->getAssessmentMode());
  }

  /**
   * Saving a draft leaves every snapshot row as it was.
   */
  public function testDraftDoesNotChangeSnapshots(): void {
    $free = $this->addCriterion('health', Criterion::TIER_FREE);
    $snapshot = $this->saveSnapshot(
      $this->scores(['health' => 4]),
      $this->answerItems([$free], 'yes'),
      150,
    );
    $before = $this->snapshotCount();
    $mode = $snapshot->getAssessmentMode();
    $note = $snapshot->getNote();

    $this->drafts()->store($this->owner, $this->payload(['health' => 9]));
    $this->drafts()->load($this->owner);

    $this->assertSame($before, $this->snapshotCount());
    $reloaded = $this->reloadSnapshot($snapshot);
    $this->assertSame(4, $this->scoreOn($reloaded, 'health'));
    $this->assertSame('yes', $this->answerOn($reloaded, (int) $free->id()));
    $this->assertSame($mode, $reloaded->getAssessmentMode());
    $this->assertSame($note, $reloaded->getNote());
  }

  /**
   * The wheel note stays in user.data and stays out of the API response.
   */
  public function testApiKeepsTheWheelNote(): void {
    $this->drafts()->saveScores($this->owner, $this->nullScores(), 'Нотатка чернетки');
    $stored = $this->drafts()->store($this->owner, $this->payload(['health' => 4]));
    $this->assertSame(4, $stored->scores['health']);
    $this->assertSame('Нотатка чернетки', $stored->note);
    $loaded = $this->drafts()->load($this->owner);
    $this->assertSame('Нотатка чернетки', $loaded->note);

    $body = json_encode($this->payload(['health' => 4]), JSON_THROW_ON_ERROR);
    $response = $this->post($body);
    $decoded = json_decode($response->getContent(), TRUE);
    $this->assertIsArray($decoded);
    $this->assertArrayNotHasKey('note', $decoded);

    try {
      $this->drafts()->saveScores($this->owner, $this->nullScores(), str_repeat('а', 1001));
      $this->fail('A long note must be refused.');
    }
    catch (DraftRejected $rejected) {
      $this->assertSame(DraftRejected::INVALID, $rejected->statusCode);
    }
    $this->assertSame('Нотатка чернетки', $this->drafts()->load($this->owner)->note);
  }

  /**
   * The route is POST-only, and the controller enforces size and flood.
   */
  public function testRouteAndController(): void {
    $provider = $this->container->get('router.route_provider');
    $route = $provider->getRouteByName('lifebalance.draft');
    $this->assertSame('/api/draft', $route->getPath());
    $this->assertSame(['POST'], $route->getMethods());
    $this->assertSame(
      'Drupal\lifebalance\Controller\DraftController:save',
      $route->getDefault('_controller'),
    );
    $this->assertSame('TRUE', $route->getRequirement('_user_is_logged_in'));
    $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));

    try {
      $this->drafts()->load(new AnonymousUserSession());
      $this->fail('An anonymous draft must be refused.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('account', $exception->getMessage());
    }

    $invalid = $this->post('not-json');
    $this->assertSame(DraftRejected::INVALID, $invalid->getStatusCode());
    $this->assertSame('{}', $invalid->getContent());
    $this->assertPrivate($invalid);

    $oversized = $this->post(str_repeat('a', DraftStorage::MAX_BYTES + 1));
    $this->assertSame(DraftRejected::INVALID, $oversized->getStatusCode());
    $this->assertSame('{}', $oversized->getContent());

    $body = json_encode($this->payload(['health' => 3]), JSON_THROW_ON_ERROR);
    $saved = $this->post($body);
    $this->assertSame(200, $saved->getStatusCode());
    $this->assertPrivate($saved);
    $decoded = json_decode($saved->getContent(), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame(3, $decoded['scores']['health']);
    $this->assertSame([], $decoded['outdated']);

    $other = $this->createUser();
    $this->setCurrentUser($other);
    $otherBody = json_encode($this->payload(['health' => 2]), JSON_THROW_ON_ERROR);
    $otherSaved = $this->post($otherBody);
    $this->assertSame(200, $otherSaved->getStatusCode());
    $ownerStored = $this->stored($this->owner);
    $this->assertIsArray($ownerStored);
    $this->assertSame(3, $ownerStored['scores']['health']);
    $otherStored = $this->stored($other);
    $this->assertIsArray($otherStored);
    $this->assertSame(2, $otherStored['scores']['health']);

    $this->setCurrentUser($this->owner);
    $this->container->get('flood')->clear(
      'lifebalance.draft',
      'uid-' . $this->owner->id(),
    );
    for ($i = 0; $i < 120; $i++) {
      $response = $this->post('{}');
      $this->assertSame(DraftRejected::INVALID, $response->getStatusCode());
    }
    $blocked = $this->post('{}');
    $this->assertSame(429, $blocked->getStatusCode());
    $this->assertSame('{}', $blocked->getContent());
    $this->assertPrivate($blocked);
  }

  /**
   * The response must not be stored by a shared cache.
   */
  private function assertPrivate(JsonResponse $response): void {
    $cache = $response->headers->get('Cache-Control');
    $this->assertIsString($cache);
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
  }

  /**
   * Posts a raw body to the draft controller.
   */
  private function post(string $body): JsonResponse {
    $request = Request::create('/api/draft', 'POST', [], [], [], [], $body);
    return $this->container->get(DraftController::class)->save($request);
  }

  /**
   * Refuses a payload with the expected status and no write.
   *
   * @param array<mixed> $payload
   *   Decoded body.
   * @param int $status
   *   Expected HTTP status.
   */
  private function assertRejected(array $payload, int $status): void {
    try {
      $this->drafts()->store($this->owner, $payload);
      $this->fail('The draft must be refused.');
    }
    catch (DraftRejected $rejected) {
      $this->assertSame($status, $rejected->statusCode);
    }
  }

  /**
   * Enables Premium for the owner.
   */
  private function grantPremium(): void {
    $this->grantEntitlement($this->owner);
    $this->setCurrentUser($this->owner);
  }

  /**
   * Removes Premium from the owner.
   */
  private function revokePremium(): void {
    $this->revokeEntitlements($this->owner);
    $this->setCurrentUser($this->owner);
  }

  /**
   * Body with one score overridden and every other sector NULL.
   *
   * @param array<string, int|null> $overrides
   *   Scores to replace.
   *
   * @return array<string, mixed>
   *   Decoded draft body.
   */
  private function payload(array $overrides = []): array {
    $scores = $this->nullScores();
    foreach ($overrides as $sector => $value) {
      $scores[$sector] = $value;
    }
    return [
      'scores' => $scores,
      'answers' => [],
    ];
  }

  /**
   * Payload with one sector changed to a value that may be invalid.
   *
   * @param string $sector
   *   Sector ID, or "missing" to drop health.
   * @param mixed $value
   *   Submitted score.
   *
   * @return array<string, mixed>
   *   Decoded draft body.
   */
  private function withScore(string $sector, mixed $value): array {
    $payload = $this->payload();
    if ($sector === 'missing') {
      unset($payload['scores']['health']);
      return $payload;
    }
    $payload['scores'][$sector] = $value;
    return $payload;
  }

  /**
   * Payload with one answer.
   *
   * @param string $id
   *   Criterion ID.
   * @param mixed $revision
   *   Submitted revision.
   * @param string $answer
   *   Submitted answer.
   *
   * @return array<string, mixed>
   *   Decoded draft body.
   */
  private function withAnswer(string $id, mixed $revision, string $answer): array {
    return $this->withAnswerShape($id, [
      'rev' => $revision,
      'a' => $answer,
    ]);
  }

  /**
   * Payload with a raw answer object.
   *
   * @param string $id
   *   Criterion ID.
   * @param array<mixed> $answer
   *   Answer object.
   *
   * @return array<string, mixed>
   *   Decoded draft body.
   */
  private function withAnswerShape(string $id, array $answer): array {
    $payload = $this->payload();
    $payload['answers'][$id] = $answer;
    return $payload;
  }

  /**
   * NULL for every active sector.
   *
   * @return array<string, int|null>
   *   Scores keyed by sector ID.
   */
  private function nullScores(): array {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[$sector] = NULL;
    }
    return $scores;
  }

  /**
   * Snapshot score items. Missing sectors use 0.
   *
   * @param array<string, int> $values
   *   Score overrides.
   *
   * @return list<array<string, int|string>>
   *   Field items.
   */
  private function scores(array $values = []): array {
    $items = [];
    foreach (self::SECTORS as $sector) {
      $items[] = [
        'sector_id' => $sector,
        'value' => $values[$sector] ?? 0,
      ];
    }
    return $items;
  }

  /**
   * Creates a criterion owned by the draft owner.
   */
  private function addCriterion(
    string $sector,
    string $tier,
    string $status = Criterion::STATUS_PUBLISHED,
  ): Criterion {
    $this->sequence++;
    $criterion = Criterion::create([
      'key' => sprintf('draft.t%02d', $this->sequence),
      'sector' => $sector,
      'tier' => $tier,
      'text' => 'Твердження для чернетки',
      'weight' => $this->sequence,
      'status' => $status,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
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
   *   Stored answer.
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
   * Saves a snapshot for the owner.
   *
   * @param list<array<string, int|string>> $scores
   *   Score items.
   * @param list<array<string, int|string>> $answers
   *   Answer items.
   * @param int $created
   *   UTC timestamp.
   */
  private function saveSnapshot(array $scores, array $answers, int $created): Snapshot {
    $snapshot = Snapshot::create([
      'uid' => $this->owner->id(),
      'created' => $created,
      'note' => '',
      'scores' => $scores,
      'answers' => $answers,
    ]);
    $snapshot->save();
    return $this->reloadSnapshot($snapshot);
  }

  /**
   * Reloads a snapshot from storage.
   */
  private function reloadSnapshot(Snapshot $snapshot): Snapshot {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([(int) $snapshot->id()]);
    $loaded = $storage->load($snapshot->id());
    $this->assertInstanceOf(Snapshot::class, $loaded);
    return $loaded;
  }

  /**
   * Stored score for one sector.
   */
  private function scoreOn(Snapshot $snapshot, string $sector): int {
    foreach ($snapshot->getScores() as $item) {
      if ($item->getSectorId() === $sector) {
        return $item->getScore();
      }
    }
    throw new \RuntimeException('Missing sector score.');
  }

  /**
   * Stored revision for one criterion.
   */
  private function revisionOn(Snapshot $snapshot, int $criterion_id): int {
    foreach ($snapshot->getAnswers() as $item) {
      if ($item->getCriterionId() === $criterion_id) {
        return $item->getCriterionRevisionId();
      }
    }
    throw new \RuntimeException('Missing answer revision.');
  }

  /**
   * Stored answer for one criterion.
   */
  private function answerOn(Snapshot $snapshot, int $criterion_id): string {
    foreach ($snapshot->getAnswers() as $item) {
      if ($item->getCriterionId() === $criterion_id) {
        return $item->getAnswer();
      }
    }
    throw new \RuntimeException('Missing answer.');
  }

  /**
   * Number of snapshot rows.
   */
  private function snapshotCount(): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('lifebalance_snapshot')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $this->owner->id())
      ->execute();
    return count($ids);
  }

  /**
   * Raw user.data value.
   *
   * @return mixed
   *   Stored draft, or NULL.
   */
  private function stored(UserInterface $account): mixed {
    return $this->userData()->get(
      DraftStorage::MODULE,
      (int) $account->id(),
      DraftStorage::NAME,
    );
  }

  /**
   * Draft storage.
   */
  private function drafts(): DraftStorage {
    return $this->container->get(DraftStorage::class);
  }

  /**
   * User data service.
   */
  private function userData(): UserDataInterface {
    return $this->container->get('user.data');
  }

}
