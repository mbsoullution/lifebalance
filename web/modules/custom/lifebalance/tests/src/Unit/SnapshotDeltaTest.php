<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Service\SnapshotDelta;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Score deltas, guide compatibility, and changed answers.
 */
#[Group('lifebalance')]
final class SnapshotDeltaTest extends UnitTestCase {

  /**
   * Shared sectors get a delta. A sector on only one side does not.
   */
  public function testRowsUseTheUnionAndSkipMissingDeltas(): void {
    $delta = new SnapshotDelta();
    $rows = $delta->rows(
      ['health' => 4, 'purpose' => 0, 'legacy' => 8],
      ['health' => 6, 'purpose' => 0, 'finances' => 3],
      ['health', 'relationships', 'purpose', 'finances'],
    );
    $this->assertSame([
      [
        'sector' => 'health',
        'left' => 4,
        'right' => 6,
        'delta' => 2,
      ],
      [
        'sector' => 'purpose',
        'left' => 0,
        'right' => 0,
        'delta' => 0,
      ],
      [
        'sector' => 'finances',
        'left' => NULL,
        'right' => 3,
        'delta' => NULL,
      ],
      [
        'sector' => 'legacy',
        'left' => 8,
        'right' => NULL,
        'delta' => NULL,
      ],
    ], $rows);
    $this->assertSame('+2', $delta->formatDelta(2));
    $this->assertSame('−1', $delta->formatDelta(-1));
    $this->assertSame('0', $delta->formatDelta(0));
    $this->assertSame('—', $delta->formatDelta(NULL));
  }

  /**
   * Free and premium guides are not treated as the same scale.
   */
  public function testGuidesMatchOnlyInsideOneModeAndFormula(): void {
    $delta = new SnapshotDelta();
    $this->assertTrue($delta->guidesComparable('free', 'v1', 'free', 'v1'));
    $this->assertFalse($delta->guidesComparable('free', 'v1', 'premium', 'v1'));
    $this->assertFalse($delta->guidesComparable('free', 'v1', 'free', 'v2'));
    $this->assertFalse($delta->guidesComparable('none', NULL, 'none', NULL));
    $this->assertFalse($delta->guidesComparable('free', '', 'free', ''));
  }

  /**
   * Only a same-revision answer that changed is reported.
   */
  public function testChangedAnswersRequireTheSameRevision(): void {
    $delta = new SnapshotDelta();
    $changes = $delta->changedAnswers(
      [
        ['criterion_id' => 2, 'revision_id' => 20, 'answer' => 'yes'],
        ['criterion_id' => 1, 'revision_id' => 10, 'answer' => 'no'],
        ['criterion_id' => 3, 'revision_id' => 30, 'answer' => 'partial'],
      ],
      [
        ['criterion_id' => 1, 'revision_id' => 11, 'answer' => 'yes'],
        ['criterion_id' => 2, 'revision_id' => 20, 'answer' => 'partial'],
        ['criterion_id' => 3, 'revision_id' => 30, 'answer' => 'partial'],
      ],
    );
    $this->assertSame([
      [
        'criterion_id' => 2,
        'revision_id' => 20,
        'left' => 'yes',
        'right' => 'partial',
      ],
    ], $changes);
  }

}
