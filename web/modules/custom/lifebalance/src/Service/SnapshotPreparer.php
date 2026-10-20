<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Plugin\Field\FieldType\AnswerItem;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;

/**
 * Fills guide, answered, assessment mode, formula version, and catalog version.
 *
 * Values sent by the client for those fields are replaced. The person's score
 * is left untouched. After the first save, scores, answers, and the stored
 * catalog version cannot change.
 */
final class SnapshotPreparer {

  public function __construct(
    private readonly GuideCalculator $guideCalculator,
    private readonly SectorRepository $sectorRepository,
    private readonly CriterionRepository $criterionRepository,
    private readonly EntitlementCheckerInterface $entitlementChecker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
    private readonly CatalogVersion $catalogVersion,
  ) {}

  /**
   * Checks ownership and writes the derived fields onto the snapshot.
   */
  public function prepare(Snapshot $snapshot): void {
    $uid = (int) $snapshot->getOwnerId();
    if ($uid < 1 || $uid !== (int) $this->currentUser->id()) {
      throw new EntityStorageException('A snapshot can be saved only by its owner.');
    }
    if (!$snapshot->isNew()) {
      $original = $snapshot->getOriginal();
      if (!$original instanceof Snapshot) {
        throw new EntityStorageException('Cannot update a snapshot without its previous values.');
      }
      if ((int) $original->getOwnerId() !== $uid) {
        throw new EntityStorageException('Snapshot owner cannot be changed.');
      }
      if ($this->signature($original) !== $this->signature($snapshot)) {
        throw new EntityStorageException('Scores and answers cannot be changed.');
      }
      // A later note edit must keep the guide, mode, formula, and catalog
      // version that were stored. Recomputing them would follow newer data.
      return;
    }

    $review = $this->review($snapshot);
    foreach ($snapshot->getScores() as $item) {
      $result = $review->guides[$item->getSectorId()] ?? NULL;
      if (!$result instanceof GuideResult) {
        continue;
      }
      $item->set('guide', $result->guide);
      $item->set('answered', $result->answered);
    }
    $snapshot->set('assessment_mode', $review->mode);
    $snapshot->set('formula_version', $review->formulaVersion);
    $snapshot->set('catalog_version', $this->catalogVersion->current());
  }

  /**
   * Reviews the snapshot without writing to it.
   */
  public function review(Snapshot $snapshot): SnapshotReview {
    $configured = $this->configFactory->get('lifebalance.settings')->get('formula_version');
    if ($configured !== GuideCalculator::VERSION) {
      throw new \LogicException('Configured formula version does not match GuideCalculator.');
    }

    $active = $this->sectorRepository->loadActive();
    $problems = [];
    if (mb_strlen($snapshot->getNote()) > 1000) {
      $problems[SnapshotProblem::NOTE_TOO_LONG] = TRUE;
    }
    $seen_sectors = [];
    foreach ($snapshot->getScores() as $item) {
      $sector_id = $item->getSectorId();
      if (isset($seen_sectors[$sector_id])) {
        $problems[SnapshotProblem::DUPLICATE_SECTOR] = TRUE;
      }
      $seen_sectors[$sector_id] = TRUE;
      if (!isset($active[$sector_id])) {
        $problems[SnapshotProblem::UNKNOWN_SECTOR] = TRUE;
      }
    }
    foreach (array_keys($active) as $sector_id) {
      if (!isset($seen_sectors[$sector_id])) {
        $problems[SnapshotProblem::MISSING_SECTOR] = TRUE;
      }
    }

    $criteria = $this->criterionRepository->loadByIds(array_map(
      static fn (AnswerItem $item): int => $item->getCriterionId(),
      $snapshot->getAnswers(),
    ));
    $premium = $this->ownerHasPremium($snapshot);
    $grouped = [];
    $seen_answers = [];
    $mode = Snapshot::MODE_NONE;
    foreach ($snapshot->getAnswers() as $item) {
      $criterion_id = $item->getCriterionId();
      if (isset($seen_answers[$criterion_id])) {
        $problems[SnapshotProblem::DUPLICATE_ANSWER] = TRUE;
      }
      $seen_answers[$criterion_id] = TRUE;
      $criterion = $criteria[$criterion_id] ?? NULL;
      if (!$criterion instanceof Criterion
        || $criterion->getStatus() !== Criterion::STATUS_PUBLISHED
        || (int) $criterion->getRevisionId() !== $item->getCriterionRevisionId()
      ) {
        $problems[SnapshotProblem::STALE_OR_UNPUBLISHED] = TRUE;
        continue;
      }
      if (!isset($active[$criterion->getSectorId()])) {
        $problems[SnapshotProblem::INACTIVE_SECTOR_ANSWER] = TRUE;
        continue;
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        $problems[SnapshotProblem::PREMIUM_DENIED] = TRUE;
        continue;
      }
      $grouped[$criterion->getSectorId()][] = new GuideAnswer(
        $criterion->getTier(),
        $item->getAnswer(),
        $criterion->isInFormula(),
      );
      if ($criterion->getTier() === Criterion::TIER_PREMIUM) {
        $mode = Snapshot::MODE_PREMIUM;
      }
      elseif ($mode === Snapshot::MODE_NONE) {
        $mode = Snapshot::MODE_FREE;
      }
    }

    $calculation_mode = $mode === Snapshot::MODE_NONE ? Snapshot::MODE_FREE : $mode;
    $guides = [];
    $formula_version = NULL;
    foreach (array_keys($active) as $sector_id) {
      $result = $this->guideCalculator->calculate($calculation_mode, $grouped[$sector_id] ?? []);
      $guides[$sector_id] = $result;
      if ($result->guide !== NULL) {
        $formula_version = GuideCalculator::VERSION;
      }
    }

    return new SnapshotReview($mode, $formula_version, $guides, array_keys($problems));
  }

  /**
   * Whether the owner may answer premium criteria.
   */
  private function ownerHasPremium(Snapshot $snapshot): bool {
    $account = $this->entityTypeManager->getStorage('user')->load($snapshot->getOwnerId());
    return $account instanceof AccountInterface && $this->entitlementChecker->hasFeatureAccess($account, 'premium_criteria');
  }

  /**
   * Scores and answers, ignoring fields the server is allowed to rewrite.
   *
   * @return array{0: list<array{0: string, 1: int}>, 1: list<array{0: int, 1: int, 2: string}>}
   *   Comparable score and answer rows.
   */
  private function signature(Snapshot $snapshot): array {
    $scores = [];
    foreach ($snapshot->getScores() as $item) {
      $scores[] = [$item->getSectorId(), $item->getScore()];
    }
    $answers = [];
    foreach ($snapshot->getAnswers() as $item) {
      $answers[] = [
        $item->getCriterionId(),
        $item->getCriterionRevisionId(),
        $item->getAnswer(),
      ];
    }
    sort($scores);
    sort($answers);
    return [$scores, $answers];
  }

}
