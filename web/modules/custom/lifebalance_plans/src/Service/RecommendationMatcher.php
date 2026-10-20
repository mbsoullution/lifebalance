<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * Orders published recommendations. The result is not stored.
 *
 * Criteria sort by "no" before "partial", then criterion weight and key.
 * At most three criteria are kept, and each keeps at most two
 * recommendations (weight, then key). A sensitive "no" becomes the support
 * block and does not take a priority slot. Keys already used by active
 * goals are left out; the caller supplies those keys.
 */
final class RecommendationMatcher {

  public const PRIORITY_LIMIT = 3;

  public const RECOMMENDATION_LIMIT = 2;

  /**
   * Suggestions for the sectors the person chose.
   *
   * @param list<\Drupal\lifebalance_plans\Service\MatchAnswer> $answers
   *   Answers that are "no" or "partial".
   * @param list<\Drupal\lifebalance_plans\Service\MatchRecommendation> $recommendations
   *   Catalog rows.
   * @param list<string> $sectorIds
   *   One or two sector IDs, in display order.
   * @param bool $premium
   *   Whether Premium recommendations may be included.
   * @param list<string> $excludedKeys
   *   Recommendation keys already used by active goals.
   * @param list<string> $sensitiveKeys
   *   Criterion keys that must not produce advice when the answer is "no".
   * @param string $supportText
   *   Configured support text.
   */
  public function forSectors(
    array $answers,
    array $recommendations,
    array $sectorIds,
    bool $premium,
    array $excludedKeys,
    array $sensitiveKeys,
    string $supportText,
  ): FocusResult {
    $support = $this->needsSupport($answers, $sensitiveKeys, $sectorIds);
    $bySector = [];
    foreach ($sectorIds as $sectorId) {
      $bySector[$sectorId] = [];
    }
    $used = [];
    $selected = 0;
    foreach ($this->priorities($answers, $sectorIds) as $answer) {
      if ($this->isSensitiveNo($answer, $sensitiveKeys)) {
        $support = TRUE;
        continue;
      }
      if ($selected >= self::PRIORITY_LIMIT) {
        break;
      }
      $selected++;
      $matched = $this->matchesFor(
        $answer,
        $recommendations,
        $premium,
        FALSE,
        $excludedKeys,
        $used,
      );
      if ($matched['support']) {
        $support = TRUE;
      }
      foreach ($matched['items'] as $item) {
        $bySector[$answer->sectorId][] = $item;
        $used[] = $item->key;
      }
    }
    $sectors = [];
    foreach ($sectorIds as $sectorId) {
      $sectors[] = new SectorSuggestions($sectorId, $bySector[$sectorId]);
    }
    return new FocusResult($sectors, $support, $support ? $supportText : '');
  }

  /**
   * The single free preview across every sector, or none.
   *
   * @param list<\Drupal\lifebalance_plans\Service\MatchAnswer> $answers
   *   Answers that are "no" or "partial".
   * @param list<\Drupal\lifebalance_plans\Service\MatchRecommendation> $recommendations
   *   Catalog rows.
   * @param list<string> $excludedKeys
   *   Recommendation keys already used by active goals.
   * @param list<string> $sensitiveKeys
   *   Criterion keys that must not produce advice when the answer is "no".
   * @param string $supportText
   *   Configured support text.
   */
  public function preview(
    array $answers,
    array $recommendations,
    array $excludedKeys,
    array $sensitiveKeys,
    string $supportText,
  ): PreviewResult {
    $support = $this->needsSupport($answers, $sensitiveKeys, NULL);
    $suggestion = NULL;
    foreach ($this->priorities($answers, NULL) as $answer) {
      if ($this->isSensitiveNo($answer, $sensitiveKeys)) {
        $support = TRUE;
        continue;
      }
      $matched = $this->matchesFor(
        $answer,
        $recommendations,
        FALSE,
        TRUE,
        $excludedKeys,
        [],
      );
      if ($matched['support']) {
        $support = TRUE;
      }
      if ($matched['items'] !== []) {
        $suggestion = $matched['items'][0];
        break;
      }
    }
    return new PreviewResult($suggestion, $support, $support ? $supportText : '');
  }

  /**
   * Whether a chosen sector has a sensitive criterion answered "no".
   *
   * @param list<\Drupal\lifebalance_plans\Service\MatchAnswer> $answers
   *   Answers that are "no" or "partial".
   * @param list<string> $sensitiveKeys
   *   Criterion keys.
   * @param list<string>|null $sectorIds
   *   Sectors to consider. NULL means every sector.
   */
  private function needsSupport(array $answers, array $sensitiveKeys, ?array $sectorIds): bool {
    foreach ($answers as $answer) {
      if ($sectorIds !== NULL && !in_array($answer->sectorId, $sectorIds, TRUE)) {
        continue;
      }
      if ($this->isSensitiveNo($answer, $sensitiveKeys)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Criteria in display order. Unanswered and not-applicable are ignored.
   *
   * @param list<\Drupal\lifebalance_plans\Service\MatchAnswer> $answers
   *   Answers from the latest snapshot.
   * @param list<string>|null $sectorIds
   *   Sectors to keep. NULL keeps every sector.
   *
   * @return list<\Drupal\lifebalance_plans\Service\MatchAnswer>
   *   One answer per criterion key.
   */
  private function priorities(array $answers, ?array $sectorIds): array {
    $chosen = [];
    foreach ($answers as $answer) {
      if ($answer->answer !== 'no' && $answer->answer !== 'partial') {
        continue;
      }
      if ($sectorIds !== NULL && !in_array($answer->sectorId, $sectorIds, TRUE)) {
        continue;
      }
      $current = $chosen[$answer->criterionKey] ?? NULL;
      if (!$current instanceof MatchAnswer || $this->criterionRank($answer) < $this->criterionRank($current)) {
        $chosen[$answer->criterionKey] = $answer;
      }
    }
    $list = array_values($chosen);
    usort($list, function (MatchAnswer $left, MatchAnswer $right): int {
      return $this->criterionRank($left) <=> $this->criterionRank($right);
    });
    return $list;
  }

  /**
   * Sort key: answer priority, criterion weight, then criterion key.
   *
   * @return list<int|string>
   *   Values compared in order.
   */
  private function criterionRank(MatchAnswer $answer): array {
    return [
      $answer->answer === 'no' ? 0 : 1,
      $answer->criterionWeight,
      $answer->criterionKey,
    ];
  }

  /**
   * A sensitive criterion answered "no" must not produce advice.
   *
   * @param \Drupal\lifebalance_plans\Service\MatchAnswer $answer
   *   One answer.
   * @param list<string> $sensitiveKeys
   *   Criterion keys.
   */
  private function isSensitiveNo(MatchAnswer $answer, array $sensitiveKeys): bool {
    return $answer->answer === 'no' && in_array($answer->criterionKey, $sensitiveKeys, TRUE);
  }

  /**
   * Up to two recommendations for one priority criterion.
   *
   * @param \Drupal\lifebalance_plans\Service\MatchAnswer $answer
   *   The priority criterion.
   * @param list<\Drupal\lifebalance_plans\Service\MatchRecommendation> $recommendations
   *   Catalog rows.
   * @param bool $premium
   *   Whether Premium recommendations may be included.
   * @param bool $previewOnly
   *   Whether only free_preview rows may be included.
   * @param list<string> $excludedKeys
   *   Recommendation keys to skip.
   * @param list<string> $usedKeys
   *   Keys already shown for an earlier priority.
   *
   * @return array{
   *   support: bool,
   *   items: list<\Drupal\lifebalance_plans\Service\Suggestion>
   *   }
   *   Showable suggestions. A sensitive row sets support and is omitted.
   */
  private function matchesFor(
    MatchAnswer $answer,
    array $recommendations,
    bool $premium,
    bool $previewOnly,
    array $excludedKeys,
    array $usedKeys,
  ): array {
    $support = FALSE;
    $rows = [];
    foreach ($recommendations as $recommendation) {
      if (!$this->accepts($recommendation, $answer, $premium, $previewOnly, $excludedKeys, $usedKeys)) {
        continue;
      }
      if ($recommendation->safety === 'sensitive') {
        $support = TRUE;
        continue;
      }
      $rows[] = $recommendation;
    }
    usort($rows, static function (MatchRecommendation $left, MatchRecommendation $right): int {
      return [$left->weight, $left->key] <=> [$right->weight, $right->key];
    });
    $items = [];
    foreach (array_slice($rows, 0, self::RECOMMENDATION_LIMIT) as $recommendation) {
      $items[] = new Suggestion(
        $recommendation->key,
        $recommendation->title,
        $recommendation->body,
        $recommendation->steps,
        $recommendation->safety === 'health',
      );
    }
    return ['support' => $support, 'items' => $items];
  }

  /**
   * Whether one catalog row can be shown for this answer.
   *
   * @param \Drupal\lifebalance_plans\Service\MatchRecommendation $recommendation
   *   One catalog row.
   * @param \Drupal\lifebalance_plans\Service\MatchAnswer $answer
   *   The priority criterion.
   * @param bool $premium
   *   Whether Premium recommendations may be included.
   * @param bool $previewOnly
   *   Whether only free_preview rows may be included.
   * @param list<string> $excludedKeys
   *   Recommendation keys to skip.
   * @param list<string> $usedKeys
   *   Keys already shown for an earlier priority.
   */
  private function accepts(
    MatchRecommendation $recommendation,
    MatchAnswer $answer,
    bool $premium,
    bool $previewOnly,
    array $excludedKeys,
    array $usedKeys,
  ): bool {
    if ($recommendation->status !== 'published') {
      return FALSE;
    }
    if ($recommendation->sectorId !== $answer->sectorId) {
      return FALSE;
    }
    if (!in_array($answer->criterionKey, $recommendation->triggerCriteria, TRUE)) {
      return FALSE;
    }
    if (!in_array($answer->answer, $recommendation->triggerAnswers, TRUE)) {
      return FALSE;
    }
    $skipped = in_array($recommendation->key, $excludedKeys, TRUE);
    $skipped = $skipped || in_array($recommendation->key, $usedKeys, TRUE);
    if ($skipped) {
      return FALSE;
    }
    if ($previewOnly) {
      return $recommendation->tier === 'free_preview';
    }
    return $recommendation->tier !== 'premium' || $premium;
  }

}
