<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Entity\Recommendation;

/**
 * Loads the latest answers and asks the matcher what to show.
 *
 * Nothing is written. Active-goal keys are supplied by the caller; goals
 * themselves are a later step.
 */
final class RecommendationEngine {

  public function __construct(
    private readonly SnapshotRepository $snapshots,
    private readonly CriterionRepository $criteria,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementCheckerInterface $entitlementChecker,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RecommendationMatcher $matcher,
  ) {}

  /**
   * The newest snapshot that contains answers.
   */
  public function latestAnswered(AccountInterface $account): ?Snapshot {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return NULL;
    }
    foreach ($this->snapshots->loadForOwner($uid) as $snapshot) {
      if ($snapshot->getAnswers() !== []) {
        return $snapshot;
      }
    }
    return NULL;
  }

  /**
   * Up to three priority criteria, and two recommendations for each.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person whose latest answers are read.
   * @param list<string> $sectorIds
   *   Sectors the person chose.
   * @param list<string> $excludedKeys
   *   Recommendation keys already used by active goals.
   */
  public function focus(AccountInterface $account, array $sectorIds, array $excludedKeys = []): FocusResult {
    $settings = $this->settings();
    return $this->matcher->forSectors(
      $this->answers($account),
      $this->catalog(),
      $sectorIds,
      $this->entitlementChecker->hasFeatureAccess($account, 'recommendations'),
      $excludedKeys,
      $settings['sensitive'],
      $settings['support'],
    );
  }

  /**
   * One free preview, with no way to store it.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person whose latest answers are read.
   * @param list<string> $excludedKeys
   *   Recommendation keys already used by active goals.
   */
  public function preview(AccountInterface $account, array $excludedKeys = []): PreviewResult {
    $settings = $this->settings();
    return $this->matcher->preview(
      $this->answers($account),
      $this->catalog(),
      $excludedKeys,
      $settings['sensitive'],
      $settings['support'],
    );
  }

  /**
   * Answers of "no" and "partial" from the latest answered snapshot.
   *
   * @return list<\Drupal\lifebalance_plans\Service\MatchAnswer>
   *   Matcher input.
   */
  private function answers(AccountInterface $account): array {
    $snapshot = $this->latestAnswered($account);
    if (!$snapshot instanceof Snapshot) {
      return [];
    }
    $answers = [];
    foreach ($snapshot->getAnswers() as $item) {
      $value = $item->getAnswer();
      if ($value !== 'no' && $value !== 'partial') {
        continue;
      }
      $criterion = $this->criteria->loadRevision($item->getCriterionRevisionId());
      if ($criterion === NULL) {
        continue;
      }
      $answers[] = new MatchAnswer(
        $criterion->getCatalogKey(),
        $criterion->getSectorId(),
        $value,
        $criterion->getWeight(),
      );
    }
    return $answers;
  }

  /**
   * Published recommendations.
   *
   * @return list<\Drupal\lifebalance_plans\Service\MatchRecommendation>
   *   Matcher input.
   */
  private function catalog(): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', Recommendation::STATUS_PUBLISHED)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof Recommendation || $entity->getStatus() !== Recommendation::STATUS_PUBLISHED) {
        continue;
      }
      $rows[] = new MatchRecommendation(
        $entity->getCatalogKey(),
        $entity->getSectorId(),
        $entity->getTriggerCriteria(),
        $entity->getTriggerAnswers(),
        $entity->getTitle(),
        $entity->getBody(),
        $entity->getSteps(),
        $entity->getSafety(),
        $entity->getTier(),
        $entity->getStatus(),
        $entity->getWeight(),
      );
    }
    return $rows;
  }

  /**
   * Support text and the sensitive criterion keys.
   *
   * @return array{support: string, sensitive: list<string>}
   *   Settings used by the matcher.
   */
  private function settings(): array {
    $config = $this->configFactory->get('lifebalance_plans.settings');
    $support = $config->get('support_block');
    $stored = $config->get('sensitive_criteria');
    $sensitive = [];
    if (is_array($stored)) {
      foreach ($stored as $key) {
        if (is_string($key) && $key !== '') {
          $sensitive[] = $key;
        }
      }
    }
    return [
      'support' => is_string($support) ? $support : '',
      'sensitive' => $sensitive,
    ];
  }

}
