<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Service\TrendGeometry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The trend axis is proportional to time and capped at 500 points.
 */
#[Group('lifebalance')]
final class TrendGeometryTest extends UnitTestCase {

  /**
   * Newer points beyond the cap are kept. X follows the time span.
   */
  public function testLimitAndProportionalAxis(): void {
    $geometry = new TrendGeometry();
    $points = [];
    for ($i = 0; $i < 501; $i++) {
      $points[] = ['time' => 1000 + $i, 'value' => $i % 11];
    }
    $kept = $geometry->limit($points);
    $this->assertCount(TrendGeometry::LIMIT, $kept);
    $this->assertSame(1001, $kept[0]['time']);
    $this->assertSame(1500, $kept[TrendGeometry::LIMIT - 1]['time']);

    $chart = $geometry->build([
      ['time' => 100, 'value' => 0, 'label' => 'low'],
      ['time' => 200, 'value' => NULL],
      ['time' => 300, 'value' => 10, 'label' => 'high'],
    ]);
    $this->assertSame('28.00', $chart['dots'][0]['x']);
    $this->assertSame('164.00', $chart['dots'][0]['y']);
    $this->assertSame('292.00', $chart['dots'][1]['x']);
    $this->assertSame('16.00', $chart['dots'][1]['y']);
    $this->assertSame('low', $chart['dots'][0]['label']);
    $this->assertSame('28.00,164.00 292.00,16.00', $chart['line']);
    $this->assertCount(2, $chart['dots']);
  }

  /**
   * Equal timestamps share the middle of the plot.
   */
  public function testEqualTimestampsUseOneX(): void {
    $chart = (new TrendGeometry())->build([
      ['time' => 50, 'value' => 5],
      ['time' => 50, 'value' => 5],
    ]);
    $this->assertSame('160.00', $chart['dots'][0]['x']);
    $this->assertSame('160.00', $chart['dots'][1]['x']);
    $this->assertSame('90.00', $chart['dots'][0]['y']);
  }

}
