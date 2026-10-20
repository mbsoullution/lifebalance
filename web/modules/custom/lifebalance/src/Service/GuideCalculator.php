<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Guide formula v1.
 *
 * Yes is 1, partial is 0.5, no is 0. "Не стосується" and a missing answer are
 * ignored. Statements with in_formula FALSE are ignored. Free mode uses only
 * the basic tier. Premium mode uses both tiers together.
 *
 * The displayed guide is round(10 × sum / substantive), an integer 0–10.
 * The stored guide is that integer times 10. Half values round half up.
 * Below 5 substantive answers in free mode, or 10 in premium mode, the guide
 * is NULL and the answered count is still returned.
 */
final class GuideCalculator {

  public const VERSION = 'v1';

  public const FREE_MINIMUM = 5;

  public const PREMIUM_MINIMUM = 10;

  /**
   * Half-points keep the sum exact: yes 2, partial 1, no 0.
   *
   * @var array<string, int>
   */
  private const HALF_POINTS = [
    'yes' => 2,
    'partial' => 1,
    'no' => 0,
  ];

  /**
   * Calculates the stored guide for one sector.
   *
   * @param string $mode
   *   Free or premium.
   * @param list<\Drupal\lifebalance\Service\GuideAnswer> $answers
   *   Answers already limited to this sector.
   */
  public function calculate(string $mode, array $answers): GuideResult {
    if ($mode !== 'free' && $mode !== 'premium') {
      throw new \InvalidArgumentException('Guide mode must be free or premium.');
    }
    $minimum = $mode === 'premium' ? self::PREMIUM_MINIMUM : self::FREE_MINIMUM;
    $half_points = 0;
    $answered = 0;
    foreach ($answers as $answer) {
      if ($answer->tier !== 'free' && $answer->tier !== 'premium') {
        throw new \InvalidArgumentException('Guide answer tier must be free or premium.');
      }
      if (!$answer->inFormula || ($mode === 'free' && $answer->tier === 'premium')) {
        continue;
      }
      if ($answer->answer === 'na') {
        continue;
      }
      if (!isset(self::HALF_POINTS[$answer->answer])) {
        throw new \InvalidArgumentException('Guide answer must be yes, partial, no, or na.');
      }
      $half_points += self::HALF_POINTS[$answer->answer];
      $answered++;
    }
    if ($answered < $minimum) {
      return new GuideResult(NULL, $answered);
    }
    $display = (int) round(5 * $half_points / $answered, 0, PHP_ROUND_HALF_UP);
    return new GuideResult($display * 10, $answered);
  }

}
