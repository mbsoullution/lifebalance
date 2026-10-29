<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\lifebalance\Service\GuideResult;

/**
 * One-decimal score shown beside the answer count.
 */
final class ScoreColumn {

  /**
   * Builds the score column.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $label
   *   Accessible name. Hidden unless the class shows it.
   * @param int|null $tenths
   *   Score in tenths, or NULL when it is not available.
   * @param string $labelClass
   *   Class for the label.
   *
   * @return array<string, mixed>
   *   Render element.
   */
  public static function element(
    MarkupInterface|string $label,
    ?int $tenths,
    string $labelClass = 'visually-hidden',
  ): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-score']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => ['class' => [$labelClass]],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => GuideResult::formatTenths($tenths),
        '#attributes' => [
          'class' => ['lifebalance-score__value'],
          'data-lifebalance-score-value' => 'true',
        ],
      ],
    ];
  }

}
