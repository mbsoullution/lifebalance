<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Differences between two snapshots.
 *
 * Scores are always comparable. A missing sector has no delta. Guides are
 * compared only when the caller has already decided the modes match.
 */
final class SnapshotDelta {

  /**
   * Rows for the union of sector ids.
   *
   * @param array<string, int|null> $left
   *   Scores or guides for snapshot A. A missing key is a missing sector.
   * @param array<string, int|null> $right
   *   Scores or guides for snapshot B.
   * @param list<string> $order
   *   Sector ids in display order. Unknown ids follow them.
   *
   * @return list<array{sector: string, left: int|null, right: int|null, delta: int|null}>
   *   One row per sector that appears on either side.
   */
  public function rows(array $left, array $right, array $order): array {
    $ids = [];
    foreach ($order as $id) {
      if (array_key_exists($id, $left) || array_key_exists($id, $right)) {
        $ids[] = $id;
      }
    }
    foreach ([...array_keys($left), ...array_keys($right)] as $id) {
      if (!in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }
    $rows = [];
    foreach ($ids as $id) {
      $a = array_key_exists($id, $left) ? $left[$id] : NULL;
      $b = array_key_exists($id, $right) ? $right[$id] : NULL;
      $rows[] = [
        'sector' => $id,
        'left' => $a,
        'right' => $b,
        'delta' => $this->delta($a, $b),
      ];
    }
    return $rows;
  }

  /**
   * B minus A, or NULL when either side has no value.
   */
  public function delta(?int $left, ?int $right): ?int {
    if ($left === NULL || $right === NULL) {
      return NULL;
    }
    return $right - $left;
  }

  /**
   * «+2», «−1», «0», or an em dash when there is no delta.
   */
  public function formatDelta(?int $delta): string {
    if ($delta === NULL) {
      return '—';
    }
    if ($delta > 0) {
      return '+' . $delta;
    }
    if ($delta < 0) {
      return '−' . abs($delta);
    }
    return '0';
  }

  /**
   * Guides share a scale only inside one mode and one formula version.
   */
  public function guidesComparable(string $mode_left, ?string $formula_left, string $mode_right, ?string $formula_right): bool {
    if ($mode_left === '' || $mode_left !== $mode_right) {
      return FALSE;
    }
    if ($formula_left === NULL || $formula_left === '') {
      return FALSE;
    }
    return $formula_left === $formula_right;
  }

  /**
   * Answers whose criterion revision is the same and whose choice differs.
   *
   * A different revision is omitted: the wording is not the same question.
   *
   * @param list<array{criterion_id: int, revision_id: int, answer: string}> $left
   *   Answers on snapshot A.
   * @param list<array{criterion_id: int, revision_id: int, answer: string}> $right
   *   Answers on snapshot B.
   *
   * @return list<array{criterion_id: int, revision_id: int, left: string, right: string}>
   *   Changes sorted by criterion id.
   */
  public function changedAnswers(array $left, array $right): array {
    $by_id = [];
    foreach ($right as $item) {
      $by_id[$item['criterion_id']] = $item;
    }
    $changes = [];
    foreach ($left as $item) {
      $other = $by_id[$item['criterion_id']] ?? NULL;
      if ($other === NULL || $other['revision_id'] !== $item['revision_id']) {
        continue;
      }
      if ($other['answer'] === $item['answer']) {
        continue;
      }
      $changes[] = [
        'criterion_id' => $item['criterion_id'],
        'revision_id' => $item['revision_id'],
        'left' => $item['answer'],
        'right' => $other['answer'],
      ];
    }
    usort($changes, static function (array $a, array $b): int {
      return $a['criterion_id'] <=> $b['criterion_id'];
    });
    return $changes;
  }

}
