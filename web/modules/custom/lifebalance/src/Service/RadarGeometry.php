<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Points for a static radar chart. The caller supplies already-safe values.
 */
final class RadarGeometry {

  private const CENTER = 100.0;

  private const RADIUS = 70.0;

  /**
   * Label arc above the top half. Glyphs extend outward.
   */
  private const LABEL_OUTER = 76.0;

  /**
   * Label arc outside the bottom half. Glyphs extend inward.
   */
  private const LABEL_INNER = 86.0;

  /**
   * Builds axis endpoints and the score polygon.
   *
   * @param list<array{label: string, color: string, value: int|null}> $sectors
   *   Sectors in display order. A NULL value is not assessed.
   *
   * @return array<string, mixed>
   *   Axis endpoints, ring radii, and the polygon points.
   */
  public function build(array $sectors): array {
    $count = count($sectors);
    $axes = [];
    $vertices = [];
    $wedges = [];
    $assessed = FALSE;
    foreach ($sectors as $index => $sector) {
      $angle = (-M_PI / 2) + ($count === 0 ? 0 : $index * 2 * M_PI / $count);
      $color = $this->color($sector['color']);
      $axes[] = [
        'x' => $this->position(cos($angle) * self::RADIUS),
        'y' => $this->position(sin($angle) * self::RADIUS),
        'color' => $color,
        'dashed' => $sector['value'] === NULL,
      ];
      $wedges[] = $this->wedge(
        $angle,
        $count,
        $color,
        $sector['value'],
        $this->sectorId($sector),
        $sector['label'],
      );
      $ratio = $sector['value'] === NULL ? 0.0 : $sector['value'] / 10;
      if ($sector['value'] !== NULL) {
        $assessed = TRUE;
      }
      $vertices[] = $this->position(cos($angle) * self::RADIUS * $ratio)
        . ','
        . $this->position(sin($angle) * self::RADIUS * $ratio);
    }
    $rings = [];
    for ($step = 1; $step <= 5; $step++) {
      $rings[] = $this->length(self::RADIUS * $step / 5);
    }
    return [
      'axes' => $axes,
      'rings' => $rings,
      'wedges' => $wedges,
      'labels' => $this->labels($sectors),
      'points' => $assessed ? implode(' ', $vertices) : '',
    ];
  }

  /**
   * Two score polygons on one radar.
   *
   * A missing value is left out of that polygon. The table shows an em dash.
   * A is a solid shape with square markers. B is dashed with circle markers.
   *
   * @param list<array{label: string, color: string, left: int|null, right: int|null}> $sectors
   *   Sectors in display order.
   *
   * @return array<string, mixed>
   *   Axes, rings, and two overlay series.
   */
  public function overlay(array $sectors): array {
    $count = count($sectors);
    $axes = [];
    $left_points = [];
    $right_points = [];
    $left_markers = [];
    $right_markers = [];
    $wedges = [];
    foreach ($sectors as $index => $sector) {
      $angle = (-M_PI / 2) + ($count === 0 ? 0 : $index * 2 * M_PI / $count);
      $left = $sector['left'];
      $right = $sector['right'];
      $color = $this->color($sector['color']);
      $axes[] = [
        'x' => $this->position(cos($angle) * self::RADIUS),
        'y' => $this->position(sin($angle) * self::RADIUS),
        'color' => $color,
        'dashed' => $left === NULL && $right === NULL,
      ];
      $wedges[] = $this->wedge($angle, $count, $color, $left, '', $sector['label']);
      if ($left !== NULL) {
        $left_points[] = $this->vertex($angle, $left);
        $left_markers[] = $this->marker($angle, $left, 'square');
      }
      if ($right !== NULL) {
        $right_points[] = $this->vertex($angle, $right);
        $right_markers[] = $this->marker($angle, $right, 'circle');
      }
    }
    $rings = [];
    for ($step = 1; $step <= 5; $step++) {
      $rings[] = $this->length(self::RADIUS * $step / 5);
    }
    return [
      'axes' => $axes,
      'rings' => $rings,
      'wedges' => $wedges,
      'labels' => $this->labels($sectors),
      'points' => '',
      'overlay' => [
        [
          'points' => implode(' ', $left_points),
          'fill' => '#1F7A4D',
          'opacity' => '0.25',
          'stroke' => '#1F7A4D',
          'dashed' => FALSE,
          'markers' => $left_markers,
        ],
        [
          'points' => implode(' ', $right_points),
          'fill' => 'none',
          'opacity' => '0',
          'stroke' => '#1F4E79',
          'dashed' => TRUE,
          'markers' => $right_markers,
        ],
      ],
    ];
  }

  /**
   * One scored slice between this spoke and the next.
   *
   * @return array{color: string, score: string, hit: string, id: string, label: string}
   *   Score is empty when the sector is not assessed. Hit is the full slice
   *   when the sector can scroll to its form.
   */
  private function wedge(float $angle, int $count, string $color, ?int $value, string $id, string $label): array {
    $ratio = $value === NULL ? 0.0 : $value / 10;
    return [
      'color' => $color,
      'score' => $this->wedgePath($angle, $count, $ratio),
      'hit' => $id === '' ? '' : $this->wedgePath($angle, $count, 1.0),
      'id' => $id,
      'label' => $label,
    ];
  }

  /**
   * Sector machine name, or empty when this chart is not a form control.
   *
   * @param array{label: string, color: string, value: int|null, id?: string} $sector
   *   One sector row.
   */
  private function sectorId(array $sector): string {
    $id = $sector['id'] ?? '';
    if (preg_match('/^[a-z0-9_]+$/', $id) !== 1) {
      return '';
    }
    return $id;
  }

  /**
   * The slice between this spoke and the next, out to the score.
   */
  private function wedgePath(float $angle, int $count, float $ratio): string {
    if ($ratio <= 0 || $count < 1) {
      return '';
    }
    $radius = self::RADIUS * $ratio;
    $end = $angle + (2 * M_PI / $count);
    $span = $this->length($radius);
    return 'M ' . $this->length(self::CENTER) . ' ' . $this->length(self::CENTER)
      . ' L ' . $this->position(cos($angle) * $radius) . ' ' . $this->position(sin($angle) * $radius)
      . ' A ' . $span . ' ' . $span . ' 0 0 1 ' . $this->position(cos($end) * $radius) . ' ' . $this->position(sin($end) * $radius)
      . ' Z';
  }

  /**
   * Sector names on the outer arc, kept upright.
   *
   * @param list<array{label: string}> $sectors
   *   Sectors in display order.
   *
   * @return list<array{text: string, path: string}>
   *   One curved label per sector.
   */
  private function labels(array $sectors): array {
    $count = count($sectors);
    $labels = [];
    foreach ($sectors as $index => $sector) {
      $angle = (-M_PI / 2) + ($count === 0 ? 0 : $index * 2 * M_PI / $count);
      $labels[] = [
        'text' => $sector['label'],
        'path' => $this->labelPath($angle, $count),
      ];
    }
    return $labels;
  }

  /**
   * Arc for one sector name. Bottom names run left to right.
   */
  private function labelPath(float $angle, int $count): string {
    if ($count < 1) {
      return '';
    }
    $span = 2 * M_PI / $count;
    $lower = sin($angle + ($span / 2)) > 0;
    $radius = $lower ? self::LABEL_INNER : self::LABEL_OUTER;
    $from = $lower ? $angle + $span : $angle;
    $to = $lower ? $angle : $angle + $span;
    return 'M ' . $this->position(cos($from) * $radius)
      . ' ' . $this->position(sin($from) * $radius)
      . ' A ' . $this->length($radius) . ' ' . $this->length($radius)
      . ' 0 0 ' . ($lower ? '0' : '1') . ' '
      . $this->position(cos($to) * $radius)
      . ' ' . $this->position(sin($to) * $radius);
  }

  /**
   * Accepts #RRGGBB and falls back to a neutral gray.
   */
  private function color(string $color): string {
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#6b7280';
  }

  /**
   * Formats a distance, such as a ring radius.
   */
  private function length(float $value): string {
    return sprintf('%.2f', $value);
  }

  /**
   * Formats a point relative to the chart center.
   */
  private function position(float $offset): string {
    return $this->length(self::CENTER + $offset);
  }

  /**
   * A polygon vertex for one score.
   */
  private function vertex(float $angle, int $value): string {
    $ratio = $value / 10;
    return $this->position(cos($angle) * self::RADIUS * $ratio)
      . ','
      . $this->position(sin($angle) * self::RADIUS * $ratio);
  }

  /**
   * A square corner or a circle center.
   *
   * @return array{shape: string, x: string, y: string}
   *   Marker position. Squares use the top-left corner.
   */
  private function marker(float $angle, int $value, string $shape): array {
    $ratio = $value / 10;
    $x = self::CENTER + (cos($angle) * self::RADIUS * $ratio);
    $y = self::CENTER + (sin($angle) * self::RADIUS * $ratio);
    if ($shape === 'square') {
      return [
        'shape' => 'square',
        'x' => $this->length($x - 2.5),
        'y' => $this->length($y - 2.5),
      ];
    }
    return [
      'shape' => 'circle',
      'x' => $this->length($x),
      'y' => $this->length($y),
    ];
  }

}
