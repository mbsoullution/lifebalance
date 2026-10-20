<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Layout for a score trend.
 *
 * X is proportional to time. Equal timestamps share one x. Y is 0 through 10.
 * At most 500 points are kept, preferring the newest.
 */
final class TrendGeometry {

  public const LIMIT = 500;

  public const WIDTH = 320;

  public const HEIGHT = 180;

  private const PAD_X = 28;

  private const PAD_Y = 16;

  /**
   * Keeps the newest points, in chronological order.
   *
   * @param list<array{time: int, value: int|null, label?: string}> $points
   *   Points in any order.
   *
   * @return list<array{time: int, value: int|null, label?: string}>
   *   At most LIMIT points, oldest first.
   */
  public function limit(array $points): array {
    $indexed = [];
    $index = 0;
    foreach ($points as $point) {
      $indexed[] = ['index' => $index, 'point' => $point];
      $index++;
    }
    usort($indexed, static function (array $a, array $b): int {
      $time = $a['point']['time'] <=> $b['point']['time'];
      return $time !== 0 ? $time : $a['index'] <=> $b['index'];
    });
    if (count($indexed) > self::LIMIT) {
      $indexed = array_slice($indexed, -self::LIMIT);
    }
    $kept = [];
    foreach ($indexed as $item) {
      $kept[] = $item['point'];
    }
    return $kept;
  }

  /**
   * SVG geometry. Points without a value are omitted from the line.
   *
   * @param list<array{time: int, value: int|null, label?: string}> $points
   *   Chronological points.
   *
   * @return array<string, mixed>
   *   Width, height, axis labels, dots, and a polyline.
   */
  public function build(array $points): array {
    $plot = [];
    foreach ($points as $point) {
      if ($point['value'] !== NULL) {
        $plot[] = $point;
      }
    }
    $times = array_map(static fn (array $point): int => $point['time'], $plot);
    $min = $times === [] ? 0 : min($times);
    $max = $times === [] ? 0 : max($times);
    $span = $max - $min;
    $inner_w = self::WIDTH - (2 * self::PAD_X);
    $inner_h = self::HEIGHT - (2 * self::PAD_Y);
    $dots = [];
    foreach ($plot as $point) {
      $ratio = $span === 0 ? 0.5 : ($point['time'] - $min) / $span;
      $value = $point['value'];
      $dots[] = [
        'x' => $this->coordinate(self::PAD_X + ($ratio * $inner_w)),
        'y' => $this->coordinate(self::PAD_Y + ((1 - ($value / 10)) * $inner_h)),
        'label' => $point['label'] ?? (string) $value,
      ];
    }
    $pairs = [];
    foreach ($dots as $dot) {
      $pairs[] = $dot['x'] . ',' . $dot['y'];
    }
    return [
      'width' => self::WIDTH,
      'height' => self::HEIGHT,
      'axis_top' => $this->coordinate(self::PAD_Y + 4),
      'axis_bottom' => $this->coordinate(self::PAD_Y + $inner_h),
      'dots' => $dots,
      'line' => count($pairs) >= 2 ? implode(' ', $pairs) : '',
    ];
  }

  /**
   * One decimal-free coordinate, stable for tests.
   */
  private function coordinate(float $value): string {
    return sprintf('%.2f', $value);
  }

}
