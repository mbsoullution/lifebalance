<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /api/draft is private to the logged-in person.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class DraftApiTest extends BrowserTestBase {

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
   * Anonymous callers are refused, and a draft never rewrites a snapshot.
   */
  public function testDraftApi(): void {
    $anonymous = $this->postDraft('{}', NULL, FALSE);
    $this->assertSame(403, $anonymous->getStatusCode());

    $owner = $this->drupalCreateUser();
    $this->drupalLogin($owner);
    $token = $this->token();

    $missing = $this->postDraft('{}', NULL, TRUE);
    $this->assertSame(403, $missing->getStatusCode());
    $wrong = $this->postDraft('{}', 'not-the-token', TRUE);
    $this->assertSame(403, $wrong->getStatusCode());

    $invalid = $this->postDraft('{"scores":{"health":1},"answers":{}}', $token, TRUE);
    $this->assertSame(400, $invalid->getStatusCode());
    $this->assertSame('{}', (string) $invalid->getBody());
    $this->assertNull($this->stored($owner));

    $saved = $this->postDraft($this->body(['health' => 9]), $token, TRUE);
    $this->assertSame(200, $saved->getStatusCode());
    $this->assertPrivate($saved);
    $payload = json_decode((string) $saved->getBody(), TRUE);
    $this->assertIsArray($payload);
    $scores = $payload['scores'] ?? NULL;
    $this->assertIsArray($scores);
    $this->assertSame(9, $scores['health']);
    $this->assertNull($scores['purpose']);

    $criterion = $this->addCriterion($owner, 'health', Criterion::TIER_FREE);
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($owner);
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'created' => 1_700_000_000,
      'note' => 'Нотатка зрізу',
      'scores' => $this->scoreItems(['health' => 4]),
      'answers' => [
        [
          'criterion_id' => (int) $criterion->id(),
          'criterion_revision_id' => (int) $criterion->getRevisionId(),
          'answer' => 'yes',
        ],
      ],
    ]);
    $snapshot->save();
    $snapshotId = (int) $snapshot->id();
    $switcher->switchBack();

    $again = $this->postDraft($this->body(['health' => 9]), $token, TRUE);
    $this->assertSame(200, $again->getStatusCode());
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $storage->resetCache([$snapshotId]);
    $reloaded = $storage->load($snapshotId);
    $this->assertInstanceOf(Snapshot::class, $reloaded);
    $this->assertSame(4, $this->scoreOn($reloaded, 'health'));
    $this->assertSame('yes', $this->answerOn($reloaded, (int) $criterion->id()));
    $this->assertSame('Нотатка зрізу', $reloaded->getNote());

    $other = $this->drupalCreateUser();
    $this->drupalLogin($other);
    $otherToken = $this->token();
    $otherSaved = $this->postDraft($this->body(['health' => 2]), $otherToken, TRUE);
    $this->assertSame(200, $otherSaved->getStatusCode());
    $this->assertPrivate($otherSaved);
    $otherPayload = json_decode((string) $otherSaved->getBody(), TRUE);
    $this->assertIsArray($otherPayload);
    $otherScores = $otherPayload['scores'] ?? NULL;
    $this->assertIsArray($otherScores);
    $this->assertSame(2, $otherScores['health']);
    $ownerStored = $this->stored($owner);
    $this->assertIsArray($ownerStored);
    $ownerScores = $ownerStored['scores'] ?? NULL;
    $this->assertIsArray($ownerScores);
    $this->assertSame(9, $ownerScores['health']);

    $this->drupalLogin($owner);
    $token = $this->token();
    $premium = $this->addCriterion($owner, 'spirituality', Criterion::TIER_PREMIUM);
    $premiumBody = $this->body(['health' => 9]);
    $decoded = json_decode($premiumBody, TRUE);
    $this->assertIsArray($decoded);
    $decoded['answers'] = [
      (string) $premium->id() => [
        'rev' => (int) $premium->getRevisionId(),
        'a' => 'partial',
      ],
    ];
    $denied = $this->postDraft(
      json_encode($decoded, JSON_THROW_ON_ERROR),
      $token,
      TRUE,
    );
    $this->assertSame(403, $denied->getStatusCode());
    $this->assertSame('{}', (string) $denied->getBody());
    $after = $this->stored($owner);
    $this->assertIsArray($after);
    $afterScores = $after['scores'] ?? NULL;
    $this->assertIsArray($afterScores);
    $this->assertSame(9, $afterScores['health']);
    $afterAnswers = $after['answers'] ?? NULL;
    $this->assertIsArray($afterAnswers);
    $this->assertArrayNotHasKey((string) $premium->id(), $afterAnswers);
    $storage->resetCache([$snapshotId]);
    $unchanged = $storage->load($snapshotId);
    $this->assertInstanceOf(Snapshot::class, $unchanged);
    $this->assertSame(4, $this->scoreOn($unchanged, 'health'));
  }

  /**
   * The response must not be stored by a shared cache.
   */
  private function assertPrivate(ResponseInterface $response): void {
    $cache = $response->getHeaderLine('Cache-Control');
    $this->assertStringContainsString('private', $cache);
    $this->assertStringContainsString('no-store', $cache);
  }

  /**
   * Posts JSON to the draft route.
   */
  private function postDraft(string $body, ?string $token, bool $session): ResponseInterface {
    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    $options = [
      'headers' => $headers,
      'body' => $body,
      'http_errors' => FALSE,
    ];
    if ($session) {
      $options['cookies'] = $this->getSessionCookies();
    }
    $url = Url::fromRoute('lifebalance.draft')->setAbsolute()->toString();
    return $this->getHttpClient()->request('POST', $url, $options);
  }

  /**
   * Session CSRF token for the logged-in person.
   */
  private function token(): string {
    return trim($this->drupalGet('session/token'));
  }

  /**
   * JSON body with one overridden score.
   *
   * @param array<string, int|null> $overrides
   *   Scores to replace. Every other sector is NULL.
   */
  private function body(array $overrides): string {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[$sector] = $overrides[$sector] ?? NULL;
    }
    return json_encode([
      'scores' => $scores,
      'answers' => new \stdClass(),
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Creates a published criterion.
   */
  private function addCriterion(UserInterface $owner, string $sector, string $tier): Criterion {
    $criterion = Criterion::create([
      'key' => $sector . '.' . $tier . '.' . $owner->id(),
      'sector' => $sector,
      'tier' => $tier,
      'text' => 'Твердження для запиту',
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $owner->id(),
    ]);
    $criterion->save();
    return $criterion;
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
  private function scoreItems(array $values): array {
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
   * Raw draft for this account.
   *
   * @return mixed
   *   Stored draft, or NULL.
   */
  private function stored(UserInterface $account): mixed {
    return $this->container->get('user.data')->get(
      DraftStorage::MODULE,
      (int) $account->id(),
      DraftStorage::NAME,
    );
  }

}
