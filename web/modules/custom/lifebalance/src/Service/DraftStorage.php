<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Plugin\Field\FieldType\AnswerItem;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\user\UserDataInterface;

/**
 * Draft scores and answers in user.data.
 *
 * Nothing here writes a snapshot. A missing draft is filled from the newest
 * snapshot, and answers whose criterion revision is no longer current are
 * dropped and reported as outdated.
 */
final class DraftStorage {

  public const MODULE = 'lifebalance';

  public const NAME = 'draft';

  /**
   * Largest accepted request body, in bytes.
   */
  public const MAX_BYTES = 16384;

  /**
   * Longest note, in characters.
   */
  public const NOTE_LIMIT = 1000;

  /**
   * Stored answer values.
   *
   * @var list<string>
   */
  private const ANSWERS = [
    AnswerItem::YES,
    AnswerItem::PARTIAL,
    AnswerItem::NO,
    AnswerItem::NA,
  ];

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly SectorRepository $sectorRepository,
    private readonly CriterionRepository $criterionRepository,
    private readonly SnapshotRepository $snapshotRepository,
    private readonly EntitlementCheckerInterface $entitlementChecker,
    private readonly Clock $clock,
  ) {}

  /**
   * Draft for this account.
   *
   * When nothing is stored, scores come from the newest snapshot, or NULL for
   * each active sector when the person has no snapshot. Premium answers are
   * removed once entitlement is gone.
   */
  public function load(AccountInterface $account): Draft {
    $uid = $this->uid($account);
    $stored = $this->userData->get(self::MODULE, $uid, self::NAME);
    if (!is_array($stored)) {
      return $this->fromSnapshot($account);
    }
    $scores = $this->scoresFromStored($stored['scores'] ?? NULL);
    $updated = is_int($stored['updated'] ?? NULL) ? $stored['updated'] : 0;
    $note = $this->noteFromStored($stored);
    $parsed = $this->answersFromStored($stored['answers'] ?? NULL);
    $filtered = $this->filterAnswers($account, $parsed);
    if ($filtered['kept'] !== $parsed) {
      $this->write($uid, $scores, $filtered['kept'], $updated, $note);
    }
    return new Draft($scores, $filtered['kept'], $filtered['outdated'], $updated, $note);
  }

  /**
   * Replaces the draft. Client guide values are not part of the body.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose draft is replaced.
   * @param array<mixed> $payload
   *   Decoded JSON object with scores and answers.
   *
   * @throws \Drupal\lifebalance\Service\DraftRejected
   *   The body is invalid, or a premium answer has no entitlement.
   */
  public function store(AccountInterface $account, array $payload): Draft {
    $uid = $this->uid($account);
    $keys = array_keys($payload);
    sort($keys);
    $shape = $keys === ['answers', 'scores']
      && is_array($payload['scores'])
      && is_array($payload['answers']);
    if (!$shape) {
      throw new DraftRejected(DraftRejected::INVALID);
    }
    $scores = $this->validateScores($payload['scores']);
    $answers = $this->validateAnswers($account, $payload['answers']);
    $updated = $this->clock->now();
    $existing = $this->userData->get(self::MODULE, $uid, self::NAME);
    $note = '';
    if (is_array($existing)) {
      $note = $this->noteFromStored($existing);
    }
    $this->write($uid, $scores, $answers, $updated, $note);
    return new Draft($scores, $answers, [], $updated, $note);
  }

  /**
   * Stores slider scores and the note. Answers already in the draft stay.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose draft is updated.
   * @param array<string, int|null> $scores
   *   One value per active sector. NULL means not assessed.
   * @param string $note
   *   Note from the wheel form.
   *
   * @throws \Drupal\lifebalance\Service\DraftRejected
   *   A score is not acceptable, or the note is longer than 1000 characters.
   */
  public function saveScores(AccountInterface $account, array $scores, string $note): Draft {
    if (mb_strlen($note) > self::NOTE_LIMIT) {
      throw new DraftRejected(DraftRejected::INVALID);
    }
    $uid = $this->uid($account);
    $normalized = $this->validateScores($scores);
    $current = $this->load($account);
    $updated = $this->clock->now();
    $this->write($uid, $normalized, $current->answers, $updated, $note);
    return new Draft($normalized, $current->answers, [], $updated, $note);
  }

  /**
   * Deletes the stored draft. The next load starts from the newest snapshot.
   */
  public function clear(AccountInterface $account): void {
    $this->userData->delete(self::MODULE, $this->uid($account), self::NAME);
  }

  /**
   * Builds a draft from the newest snapshot without writing user.data.
   */
  private function fromSnapshot(AccountInterface $account): Draft {
    $scores = $this->emptyScores();
    $parsed = [];
    $snapshot = $this->snapshotRepository->loadLatestForOwner($this->uid($account));
    if ($snapshot instanceof Snapshot) {
      foreach ($snapshot->getScores() as $item) {
        $sector = $item->getSectorId();
        if (array_key_exists($sector, $scores)) {
          $scores[$sector] = $item->getScore();
        }
      }
      foreach ($snapshot->getAnswers() as $item) {
        $parsed[$item->getCriterionId()] = [
          'rev' => $item->getCriterionRevisionId(),
          'a' => $item->getAnswer(),
        ];
      }
    }
    $filtered = $this->filterAnswers($account, $parsed);
    return new Draft($scores, $filtered['kept'], $filtered['outdated'], 0);
  }

  /**
   * Active-sector scores, using NULL where the stored draft has no value.
   *
   * @return array<string, int|null>
   *   Scores keyed by sector ID, in sector weight order.
   */
  private function scoresFromStored(mixed $scores): array {
    $normalized = $this->emptyScores();
    if (!is_array($scores)) {
      return $normalized;
    }
    foreach (array_keys($normalized) as $sector) {
      if (!array_key_exists($sector, $scores)) {
        continue;
      }
      $stored = $scores[$sector];
      if (is_int($stored) && $stored >= 0 && $stored <= 10) {
        $normalized[$sector] = $stored;
      }
    }
    return $normalized;
  }

  /**
   * Answers that have the stored shape. Anything else is ignored.
   *
   * @return array<int, array{rev: int, a: string}>
   *   Answers keyed by criterion ID.
   */
  private function answersFromStored(mixed $answers): array {
    if (!is_array($answers)) {
      return [];
    }
    $parsed = [];
    foreach ($answers as $key => $value) {
      $id = $this->criterionId($key);
      if ($id === NULL || !is_array($value)) {
        continue;
      }
      $revision = $value['rev'] ?? NULL;
      $answer = $value['a'] ?? NULL;
      if (!is_int($revision) || $revision < 1 || !is_string($answer)) {
        continue;
      }
      if (!in_array($answer, self::ANSWERS, TRUE)) {
        continue;
      }
      $parsed[$id] = [
        'rev' => $revision,
        'a' => $answer,
      ];
    }
    ksort($parsed);
    return $parsed;
  }

  /**
   * Keeps current published answers and lists the ones that went stale.
   *
   * Premium answers are removed when the account has no entitlement. They are
   * not marked outdated: the wording did not change.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose entitlement is checked.
   * @param array<int, array{rev: int, a: string}> $answers
   *   Candidate answers.
   *
   * @return array{kept: array<int, array{rev: int, a: string}>, outdated: list<int>}
   *   Answers still valid, and IDs whose revision is no longer current.
   */
  private function filterAnswers(AccountInterface $account, array $answers): array {
    $criteria = $this->criterionRepository->loadByIds(array_keys($answers));
    $active = $this->sectorRepository->loadActive();
    $premium = $this->entitlementChecker->hasFeatureAccess($account, 'premium_criteria');
    $kept = [];
    $outdated = [];
    foreach ($answers as $id => $answer) {
      $criterion = $criteria[$id] ?? NULL;
      if (!$criterion instanceof Criterion
        || $criterion->getStatus() !== Criterion::STATUS_PUBLISHED
        || !isset($active[$criterion->getSectorId()])
        || (int) $criterion->getRevisionId() !== $answer['rev']
      ) {
        $outdated[] = $id;
        continue;
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        continue;
      }
      $kept[$id] = $answer;
    }
    sort($outdated);
    return ['kept' => $kept, 'outdated' => $outdated];
  }

  /**
   * Scores for every active sector, or a rejection with no field detail.
   *
   * @param array<mixed> $scores
   *   Submitted scores.
   *
   * @return array<string, int|null>
   *   Scores keyed by sector ID.
   *
   * @throws \Drupal\lifebalance\Service\DraftRejected
   *   A sector is missing, unknown, or the value is not NULL or 0 through 10.
   */
  private function validateScores(array $scores): array {
    $expected = array_keys($this->sectorRepository->loadActive());
    $given = [];
    foreach (array_keys($scores) as $key) {
      if (!is_string($key)) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      $given[] = $key;
    }
    $expectedSorted = $expected;
    $givenSorted = $given;
    sort($expectedSorted);
    sort($givenSorted);
    if ($expectedSorted !== $givenSorted) {
      throw new DraftRejected(DraftRejected::INVALID);
    }
    $normalized = [];
    foreach ($expected as $sector) {
      $value = $scores[$sector];
      if ($value !== NULL && (!is_int($value) || $value < 0 || $value > 10)) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      $normalized[$sector] = $value;
    }
    return $normalized;
  }

  /**
   * Published answers on the current revision, for active sectors only.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account whose entitlement is checked.
   * @param array<mixed> $answers
   *   Submitted answers.
   *
   * @return array<int, array{rev: int, a: string}>
   *   Answers keyed by criterion ID.
   *
   * @throws \Drupal\lifebalance\Service\DraftRejected
   *   An answer is not acceptable, or premium content lacks entitlement.
   */
  private function validateAnswers(AccountInterface $account, array $answers): array {
    $parsed = [];
    foreach ($answers as $key => $value) {
      $id = $this->criterionId($key);
      if ($id === NULL || !is_array($value)) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      $valueKeys = array_keys($value);
      sort($valueKeys);
      $revision = $value['rev'] ?? NULL;
      $answer = $value['a'] ?? NULL;
      if ($valueKeys !== ['a', 'rev'] || !is_int($revision) || $revision < 1) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      if (!is_string($answer) || !in_array($answer, self::ANSWERS, TRUE)) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      $parsed[$id] = [
        'rev' => $revision,
        'a' => $answer,
      ];
    }
    ksort($parsed);

    $criteria = $this->criterionRepository->loadByIds(array_keys($parsed));
    $active = $this->sectorRepository->loadActive();
    $premium = $this->entitlementChecker->hasFeatureAccess($account, 'premium_criteria');
    $premiumDenied = FALSE;
    foreach ($parsed as $id => $answer) {
      $criterion = $criteria[$id] ?? NULL;
      if (!$criterion instanceof Criterion
        || $criterion->getStatus() !== Criterion::STATUS_PUBLISHED
        || !isset($active[$criterion->getSectorId()])
        || (int) $criterion->getRevisionId() !== $answer['rev']
      ) {
        throw new DraftRejected(DraftRejected::INVALID);
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        $premiumDenied = TRUE;
      }
    }
    if ($premiumDenied) {
      throw new DraftRejected(DraftRejected::FORBIDDEN);
    }
    return $parsed;
  }

  /**
   * NULL score for every active sector, in weight order.
   *
   * @return array<string, int|null>
   *   Scores keyed by sector ID.
   */
  private function emptyScores(): array {
    $scores = [];
    foreach (array_keys($this->sectorRepository->loadActive()) as $id) {
      $scores[$id] = NULL;
    }
    return $scores;
  }

  /**
   * Writes the 5.5 shape. outdated is not stored.
   *
   * @param int $uid
   *   Account ID.
   * @param array<string, int|null> $scores
   *   Sector scores.
   * @param array<int, array{rev: int, a: string}> $answers
   *   Answers keyed by criterion ID.
   * @param int $updated
   *   Unix timestamp to store.
   * @param string $note
   *   Wheel note. Omitted from the draft API response.
   */
  private function write(int $uid, array $scores, array $answers, int $updated, string $note): void {
    $stored = [];
    foreach ($answers as $id => $answer) {
      $stored[(string) $id] = [
        'rev' => $answer['rev'],
        'a' => $answer['a'],
      ];
    }
    $this->userData->set(self::MODULE, $uid, self::NAME, [
      'scores' => $scores,
      'answers' => $stored,
      'updated' => $updated,
      'note' => $note,
    ]);
  }

  /**
   * Note stored beside the draft, or an empty string.
   *
   * @param array<mixed> $stored
   *   Raw user.data value.
   */
  private function noteFromStored(array $stored): string {
    $note = $stored['note'] ?? '';
    return is_string($note) ? $note : '';
  }

  /**
   * Positive criterion ID, or NULL when the key is not a canonical integer.
   */
  private function criterionId(mixed $key): ?int {
    if (is_int($key) && $key > 0) {
      return $key;
    }
    if (!is_string($key) || preg_match('/^[1-9][0-9]*$/', $key) !== 1) {
      return NULL;
    }
    $id = (int) $key;
    return (string) $id === $key ? $id : NULL;
  }

  /**
   * Account ID, refusing anonymous and a missing account.
   */
  private function uid(AccountInterface $account): int {
    $uid = (int) $account->id();
    if ($uid < 1) {
      throw new \InvalidArgumentException('A draft requires an account id.');
    }
    return $uid;
  }

}
