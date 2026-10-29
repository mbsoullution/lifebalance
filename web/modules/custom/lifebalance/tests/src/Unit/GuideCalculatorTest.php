<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Service\GuideAnswer;
use Drupal\lifebalance\Service\GuideCalculator;
use Drupal\lifebalance\Service\GuideResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guide formula v1 matches the shared fixture.
 */
#[Group('lifebalance')]
final class GuideCalculatorTest extends UnitTestCase {

  /**
   * Every fixture row produces the stored guide and the answered count.
   */
  public function testFixtureCases(): void {
    $path = dirname(__DIR__, 2) . '/fixtures/guide-cases.json';
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame(GuideCalculator::VERSION, $this->stringValue($decoded, 'formula_version'));
    $minimum = $decoded['minimum'] ?? NULL;
    $this->assertIsArray($minimum);
    $this->assertSame(GuideCalculator::FREE_MINIMUM, $this->intValue($minimum, 'free'));
    $this->assertSame(GuideCalculator::PREMIUM_MINIMUM, $this->intValue($minimum, 'premium'));

    $cases = $decoded['cases'] ?? NULL;
    $this->assertIsArray($cases);
    $calculator = new GuideCalculator();
    $names = [];
    foreach ($cases as $case) {
      $this->assertIsArray($case);
      $name = $this->stringValue($case, 'name');
      $names[] = $name;
      $mode = $this->stringValue($case, 'mode');
      $rows = $case['answers'] ?? NULL;
      $this->assertIsArray($rows);
      $answers = [];
      foreach ($rows as $row) {
        $this->assertIsArray($row);
        $in_formula = $row['in_formula'] ?? NULL;
        $this->assertIsBool($in_formula);
        $answers[] = new GuideAnswer(
          $this->stringValue($row, 'tier'),
          $this->stringValue($row, 'answer'),
          $in_formula,
        );
      }
      $result = $calculator->calculate($mode, $answers);
      $this->assertSame($this->nullableInt($case, 'guide'), $result->guide, $name);
      $this->assertSame($this->intValue($case, 'answered'), $result->answered, $name);
      $this->assertSame($this->nullableInt($case, 'precise'), $result->precise, $name);
    }
    foreach ([
      'all_yes',
      'mixed',
      'na_excluded',
      'below_minimum',
      'all_na',
      'premium_counts_twenty',
    ] as $required) {
      $this->assertContains($required, $names);
    }
  }

  /**
   * The column keeps one decimal and a comma.
   */
  public function testFormatsOneDecimal(): void {
    $this->assertSame('8,5', GuideResult::formatTenths(85));
    $this->assertSame('6,0', GuideResult::formatTenths(60));
    $this->assertSame('10,0', GuideResult::formatTenths(100));
    $this->assertSame('—', GuideResult::formatTenths(NULL));
  }

  /**
   * Unknown modes and answers are rejected.
   */
  public function testRejectsUnknownInput(): void {
    $calculator = new GuideCalculator();
    $this->expectException(\InvalidArgumentException::class);
    $calculator->calculate('none', []);
  }

  /**
   * Reads a non-empty string field.
   *
   * @param array<mixed> $data
   *   Decoded JSON object.
   * @param string $key
   *   Field name.
   */
  private function stringValue(array $data, string $key): string {
    $value = $data[$key] ?? NULL;
    if (!is_string($value) || $value === '') {
      throw new \RuntimeException($key);
    }
    return $value;
  }

  /**
   * Reads an integer field.
   *
   * @param array<mixed> $data
   *   Decoded JSON object.
   * @param string $key
   *   Field name.
   */
  private function intValue(array $data, string $key): int {
    $value = $data[$key] ?? NULL;
    if (!is_int($value)) {
      throw new \RuntimeException($key);
    }
    return $value;
  }

  /**
   * Reads an integer or null field.
   *
   * @param array<mixed> $data
   *   Decoded JSON object.
   * @param string $key
   *   Field name.
   */
  private function nullableInt(array $data, string $key): ?int {
    if (!array_key_exists($key, $data)) {
      throw new \RuntimeException($key);
    }
    $value = $data[$key];
    if ($value === NULL) {
      return NULL;
    }
    if (!is_int($value)) {
      throw new \RuntimeException($key);
    }
    return $value;
  }

}
