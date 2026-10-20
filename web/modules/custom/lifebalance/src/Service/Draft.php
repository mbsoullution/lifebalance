<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * One person's unsaved assessment.
 *
 * Scores and answers are the stored draft. outdated lists criterion IDs whose
 * wording changed, so those answers were dropped and are not stored.
 */
final class Draft {

  /**
   * Stores the draft shown to this person.
   *
   * @param array<string, int|null> $scores
   *   Sector scores. NULL means the sector is not assessed yet.
   * @param array<int, array{rev: int, a: string}> $answers
   *   Answers keyed by criterion ID.
   * @param list<int> $outdated
   *   Criterion IDs dropped because that revision is no longer current.
   * @param int $updated
   *   Unix timestamp of the last save, or 0 when nothing is stored yet.
   * @param string $note
   *   Note typed on the wheel. The draft API does not accept or return it.
   */
  public function __construct(
    public readonly array $scores,
    public readonly array $answers,
    public readonly array $outdated,
    public readonly int $updated,
    public readonly string $note = '',
  ) {}

  /**
   * JSON-ready draft, including the outdated flag.
   *
   * @return array<string, mixed>
   *   Scores, answers, outdated criterion IDs, and the update time.
   */
  public function toArray(): array {
    $answers = [];
    foreach ($this->answers as $id => $answer) {
      $answers[(string) $id] = [
        'rev' => $answer['rev'],
        'a' => $answer['a'],
      ];
    }
    return [
      'scores' => $this->scores,
      'answers' => $answers === [] ? new \stdClass() : $answers,
      'outdated' => $this->outdated,
      'updated' => $this->updated,
    ];
  }

}
