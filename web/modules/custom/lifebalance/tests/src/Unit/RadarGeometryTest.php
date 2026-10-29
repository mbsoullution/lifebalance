<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sector names follow the outer arc of the wheel.
 */
#[Group('lifebalance')]
final class RadarGeometryTest extends UnitTestCase {

  /**
   * Every sector gets a curved label. Bottom arcs run the other way.
   */
  public function testLabelsFollowTheSectorArc(): void {
    $sectors = [];
    foreach (['Health', 'Relationships', 'Environment', 'Purpose', 'Finances', 'Growth', 'Brightness', 'Spirit'] as $index => $label) {
      $sectors[] = [
        'label' => $label,
        'color' => '#2BD56A',
        'value' => $index === 7 ? NULL : 5,
      ];
    }
    $chart = (new RadarGeometry())->build($sectors);
    $this->assertCount(8, $chart['labels']);
    $this->assertSame('Health', $chart['labels'][0]['text']);
    $this->assertSame('Spirit', $chart['labels'][7]['text']);
    $this->assertStringContainsString(' 0 0 1 ', $chart['labels'][0]['path']);
    $this->assertStringContainsString(' 0 0 0 ', $chart['labels'][4]['path']);
    $this->assertNotSame('', $chart['labels'][7]['path']);
    $this->assertSame('', $chart['wedges'][7]['hit']);
  }

  /**
   * A named sector keeps a full hit area when it has no score.
   */
  public function testNamedSectorKeepsHitArea(): void {
    $chart = (new RadarGeometry())->build([
      [
        'label' => 'Health',
        'color' => '#2BD56A',
        'value' => NULL,
        'id' => 'health',
      ],
    ]);
    $this->assertSame('health', $chart['wedges'][0]['id']);
    $this->assertSame('', $chart['wedges'][0]['score']);
    $this->assertNotSame('', $chart['wedges'][0]['hit']);
  }

  /**
   * A one-decimal score fills part of the slice.
   */
  public function testDecimalScoreFillsTheSlice(): void {
    $chart = (new RadarGeometry())->build([
      [
        'label' => 'Health',
        'color' => '#2BD56A',
        'value' => 8.5,
        'id' => 'health',
      ],
    ]);
    $score = $chart['wedges'][0]['score'];
    $this->assertIsString($score);
    $this->assertStringContainsString('59.50', $score);
    $this->assertStringNotContainsString('70.00', $score);
  }

}
