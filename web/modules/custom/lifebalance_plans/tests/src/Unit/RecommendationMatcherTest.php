<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Unit;

use Drupal\lifebalance_plans\Service\MatchAnswer;
use Drupal\lifebalance_plans\Service\MatchRecommendation;
use Drupal\lifebalance_plans\Service\RecommendationMatcher;
use Drupal\lifebalance_plans\Service\Suggestion;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The matcher follows the shared fixture: order, limit, safety, and tier.
 */
#[Group('lifebalance_plans')]
final class RecommendationMatcherTest extends UnitTestCase {

  /**
   * Every fixture row produces the stored keys and the support flag.
   */
  public function testFixtureCases(): void {
    $path = dirname(__DIR__, 2) . '/fixtures/recommendation-cases.json';
    $decoded = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($decoded);
    $cases = $decoded['cases'] ?? NULL;
    $this->assertIsArray($cases);
    $matcher = new RecommendationMatcher();
    $names = [];
    foreach ($cases as $case) {
      $this->assertIsArray($case);
      $name = $this->stringValue($case, 'name');
      $names[] = $name;
      $answers = $this->answers($case);
      $recommendations = $this->recommendations($case);
      $excluded = $this->stringList($case, 'excluded');
      $sensitive = $this->stringList($case, 'sensitive');
      $mode = $this->stringValue($case, 'mode');
      if ($mode === 'preview') {
        $result = $matcher->preview($answers, $recommendations, $excluded, $sensitive, 'Підтримка');
        $expected = $case['expect_key'] ?? NULL;
        $this->assertTrue($expected === NULL || is_string($expected), $name);
        $actual = $result->suggestion instanceof Suggestion ? $result->suggestion->key : NULL;
        $this->assertSame($expected, $actual, $name);
        $this->assertSame((bool) ($case['expect_support'] ?? FALSE), $result->support, $name);
        continue;
      }
      $result = $matcher->forSectors(
        $answers,
        $recommendations,
        $this->stringList($case, 'sectors'),
        (bool) ($case['premium'] ?? FALSE),
        $excluded,
        $sensitive,
        'Підтримка',
      );
      $this->assertSame((bool) ($case['expect_support'] ?? FALSE), $result->support, $name);
      $expectKeys = $case['expect_keys'] ?? NULL;
      $this->assertIsArray($expectKeys, $name);
      $actual = [];
      $health = [];
      foreach ($result->sectors as $sector) {
        $keys = [];
        foreach ($sector->items as $item) {
          $keys[] = $item->key;
          if ($item->healthNote) {
            $health[] = $item->key;
          }
        }
        $actual[$sector->sectorId] = $keys;
      }
      $this->assertSame($expectKeys, $actual, $name);
      $expectHealth = $case['expect_health'] ?? NULL;
      $this->assertIsArray($expectHealth, $name);
      $this->assertSame(array_values($expectHealth), $health, $name);
    }
    foreach ([
      'order',
      'limit_two',
      'limit_criteria',
      'stable',
      'excluded',
      'tier_without_premium',
      'empty',
      'sensitive_no',
      'sensitive_recommendation',
      'health_note',
      'trigger_answers',
      'draft_ignored',
      'preview_one',
      'preview_none',
    ] as $required) {
      $this->assertContains($required, $names);
    }
  }

  /**
   * Answers from one fixture case.
   *
   * @param array<mixed> $case
   *   One fixture case.
   *
   * @return list<\Drupal\lifebalance_plans\Service\MatchAnswer>
   *   Matcher input.
   */
  private function answers(array $case): array {
    $rows = $case['answers'] ?? NULL;
    $this->assertIsArray($rows);
    $answers = [];
    foreach ($rows as $row) {
      $this->assertIsArray($row);
      $answers[] = new MatchAnswer(
        $this->stringValue($row, 'key'),
        $this->stringValue($row, 'sector'),
        $this->stringValue($row, 'answer'),
        $this->intValue($row, 'weight'),
      );
    }
    return $answers;
  }

  /**
   * Recommendations from one fixture case.
   *
   * @param array<mixed> $case
   *   One fixture case.
   *
   * @return list<\Drupal\lifebalance_plans\Service\MatchRecommendation>
   *   Matcher input.
   */
  private function recommendations(array $case): array {
    $rows = $case['recommendations'] ?? NULL;
    $this->assertIsArray($rows);
    $recommendations = [];
    foreach ($rows as $row) {
      $this->assertIsArray($row);
      $recommendations[] = new MatchRecommendation(
        $this->stringValue($row, 'key'),
        $this->stringValue($row, 'sector'),
        $this->stringList($row, 'triggers'),
        $this->stringList($row, 'trigger_answers'),
        'Назва',
        'Текст',
        ['Крок'],
        $this->stringValue($row, 'safety'),
        $this->stringValue($row, 'tier'),
        $this->stringValue($row, 'status'),
        $this->intValue($row, 'weight'),
      );
    }
    return $recommendations;
  }

  /**
   * A required string.
   *
   * @param array<mixed> $row
   *   A mapping.
   * @param string $key
   *   Mapping key.
   */
  private function stringValue(array $row, string $key): string {
    $value = $row[$key] ?? NULL;
    $this->assertIsString($value);
    return $value;
  }

  /**
   * A required integer.
   *
   * @param array<mixed> $row
   *   A mapping.
   * @param string $key
   *   Mapping key.
   */
  private function intValue(array $row, string $key): int {
    $value = $row[$key] ?? NULL;
    $this->assertIsInt($value);
    return $value;
  }

  /**
   * A list of strings.
   *
   * @param array<mixed> $row
   *   A mapping.
   * @param string $key
   *   Mapping key.
   *
   * @return list<string>
   *   The list.
   */
  private function stringList(array $row, string $key): array {
    $value = $row[$key] ?? NULL;
    $this->assertIsArray($value);
    $items = [];
    foreach ($value as $item) {
      $this->assertIsString($item);
      $items[] = $item;
    }
    return $items;
  }

}
