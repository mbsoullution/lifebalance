<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\user\UserDataInterface;

/**
 * Builds a download of the current person's own LifeBalance data.
 *
 * The draft is read from user.data. DraftStorage::load() is not used, because
 * it drops premium answers when entitlement is gone and may rewrite the draft.
 * Answer text comes only from the revision that was answered.
 */
final class PersonalExport {

  /**
   * CSV columns. One file holds the premium flag, the draft, and snapshots.
   *
   * @var list<string>
   */
  private const COLUMNS = [
    'row_type',
    'premium',
    'draft_updated',
    'uuid',
    'created',
    'note',
    'assessment_mode',
    'formula_version',
    'catalog_version',
    'sector_id',
    'value',
    'guide',
    'answered',
    'criterion_id',
    'criterion_key',
    'revision_id',
    'revision_text',
    'answer',
    'goal_title',
    'goal_instructions',
    'goal_status',
    'goal_origin',
    'goal_due',
    'goal_completed',
    'goal_step',
    'goal_step_done',
    'plan_id',
    'plan_status',
    'plan_starts',
    'plan_ends',
    'plan_sectors',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
    private readonly SnapshotRepository $snapshots,
    private readonly EntitlementCheckerInterface $entitlement,
  ) {}

  /**
   * Export document for this account only.
   *
   * Guide values are the stored integers (displayed score times 10), or NULL.
   * A NULL draft score means the sector was not assessed. 0 is a real score.
   * Revision text is NULL when that revision cannot be loaded.
   *
   * @return array{premium: bool, draft: array<string, mixed>|null, snapshots: list<array<string, mixed>>, goals: list<array<string, mixed>>, plans: list<array<string, mixed>>}
   *   Personal data. Nothing from another account is included.
   */
  public function document(AccountInterface $account): array {
    $uid = (int) $account->id();
    return [
      'premium' => $this->entitlement->hasPremium($account),
      'draft' => $this->draft($uid),
      'snapshots' => $this->snapshots($uid),
      'goals' => $this->goals($uid),
      'plans' => $this->plans($uid),
    ];
  }

  /**
   * CSV for this account. Cells that start a spreadsheet formula are prefixed.
   */
  public function csv(AccountInterface $account): string {
    $document = $this->document($account);
    $rows = [self::COLUMNS];
    $rows[] = $this->row([
      'row_type' => 'premium',
      'premium' => $document['premium'] ? '1' : '0',
    ]);
    foreach ($this->draftRows($document['draft']) as $row) {
      $rows[] = $row;
    }
    foreach ($document['snapshots'] as $snapshot) {
      foreach ($this->snapshotRows($snapshot) as $row) {
        $rows[] = $row;
      }
    }
    foreach ($document['goals'] as $goal) {
      foreach ($this->goalRows($goal) as $row) {
        $rows[] = $row;
      }
    }
    foreach ($document['plans'] as $plan) {
      $rows[] = $this->planRow($plan);
    }
    return $this->encode($rows);
  }

  /**
   * Stored draft, or NULL when this person has not saved one.
   *
   * @return array<string, mixed>|null
   *   Draft rows.
   */
  private function draft(int $uid): ?array {
    if ($uid < 1) {
      return NULL;
    }
    $stored = $this->userData->get('lifebalance', $uid, 'draft');
    if (!is_array($stored)) {
      return NULL;
    }
    $updated = $stored['updated'] ?? 0;
    $note = $stored['note'] ?? '';
    return [
      'updated' => is_int($updated) ? $updated : 0,
      'note' => is_string($note) ? $note : '',
      'scores' => $this->draftScores($stored['scores'] ?? NULL),
      'answers' => $this->draftAnswers($stored['answers'] ?? NULL),
    ];
  }

  /**
   * Snapshots owned by this uid, newest first.
   *
   * @return list<array<string, mixed>>
   *   Snapshot documents.
   */
  private function snapshots(int $uid): array {
    if ($uid < 1) {
      return [];
    }
    $documents = [];
    foreach ($this->snapshots->loadForOwner($uid) as $snapshot) {
      if ((int) $snapshot->getOwnerId() !== $uid) {
        continue;
      }
      $documents[] = $this->snapshotDocument($snapshot);
    }
    return $documents;
  }

  /**
   * One snapshot as plain data.
   *
   * @return array<string, mixed>
   *   Snapshot document.
   */
  private function snapshotDocument(Snapshot $snapshot): array {
    $scores = [];
    foreach ($snapshot->getScores() as $item) {
      $scores[] = [
        'sector_id' => $item->getSectorId(),
        'value' => $item->getScore(),
        'guide' => $item->getGuide(),
        'answered' => $item->getAnswered(),
      ];
    }
    $answers = [];
    foreach ($snapshot->getAnswers() as $item) {
      $wording = $this->wording($item->getCriterionId(), $item->getCriterionRevisionId());
      $answers[] = [
        'criterion_id' => $item->getCriterionId(),
        'key' => $wording['key'],
        'revision_id' => $item->getCriterionRevisionId(),
        'text' => $wording['text'],
        'answer' => $item->getAnswer(),
      ];
    }
    return [
      'uuid' => (string) $snapshot->uuid(),
      'created' => $snapshot->getCreatedTime(),
      'note' => $snapshot->getNote(),
      'assessment_mode' => $snapshot->getAssessmentMode(),
      'formula_version' => $snapshot->getFormulaVersion(),
      'catalog_version' => $snapshot->getCatalogVersion(),
      'scores' => $scores,
      'answers' => $answers,
    ];
  }

  /**
   * Goals owned by this uid, newest first.
   *
   * The plans module may be absent. The list is empty in that case.
   *
   * @return list<array<string, mixed>>
   *   Goal documents.
   */
  private function goals(int $uid): array {
    if ($uid < 1 || !$this->entityTypeManager->hasDefinition('lifebalance_goal')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_goal');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    $loaded = $storage->loadMultiple($ids);
    $documents = [];
    foreach ($ids as $id) {
      $entity = $loaded[$id] ?? NULL;
      if (!$entity instanceof ContentEntityInterface || (int) $entity->get('uid')->getString() !== $uid) {
        continue;
      }
      $documents[] = $this->goalDocument($entity);
    }
    return $documents;
  }

  /**
   * One goal as plain data, including the copied text and steps.
   *
   * @return array<string, mixed>
   *   Goal document.
   */
  private function goalDocument(ContentEntityInterface $entity): array {
    $steps = [];
    foreach ($entity->get('steps') as $item) {
      $text = $item->get('text')->getValue();
      if (!is_string($text) || $text === '') {
        continue;
      }
      $done = $item->get('done')->getValue();
      $at = $item->get('done_at')->getValue();
      $steps[] = [
        'text' => $text,
        'done' => is_numeric($done) && (int) $done === 1,
        'done_at' => is_numeric($at) && (int) $at > 0 ? (int) $at : NULL,
      ];
    }
    return [
      'uuid' => (string) $entity->uuid(),
      'sector' => $this->fieldText($entity, 'sector'),
      'origin' => $this->fieldText($entity, 'origin'),
      'title' => $this->fieldText($entity, 'title'),
      'instructions' => $this->fieldText($entity, 'instructions'),
      'status' => $this->fieldText($entity, 'status'),
      'due' => $this->nullableText($entity, 'due'),
      'completed_at' => $this->nullableInt($entity, 'completed_at'),
      'created' => $this->nullableInt($entity, 'created') ?? 0,
      'recommendation_id' => $this->nullableInt($entity, 'recommendation_id'),
      'recommendation_revision_id' => $this->nullableInt($entity, 'recommendation_revision_id'),
      'plan_id' => $entity->hasField('plan_id') ? $this->nullableInt($entity, 'plan_id') : NULL,
      'steps' => $steps,
    ];
  }

  /**
   * CSV rows for one exported goal.
   *
   * @param array<string, mixed> $goal
   *   Goal document.
   *
   * @return list<list<string>>
   *   CSV records.
   */
  private function goalRows(array $goal): array {
    $shared = [
      'row_type' => 'goal',
      'uuid' => $this->text($goal['uuid'] ?? ''),
      'created' => $this->text($goal['created'] ?? ''),
      'sector_id' => $this->text($goal['sector'] ?? ''),
      'goal_title' => $this->text($goal['title'] ?? ''),
      'goal_instructions' => $this->text($goal['instructions'] ?? ''),
      'goal_status' => $this->text($goal['status'] ?? ''),
      'goal_origin' => $this->text($goal['origin'] ?? ''),
      'goal_due' => $this->text($goal['due'] ?? ''),
      'goal_completed' => $this->text($goal['completed_at'] ?? ''),
      'revision_id' => $this->text($goal['recommendation_revision_id'] ?? ''),
      'plan_id' => $this->text($goal['plan_id'] ?? ''),
    ];
    $rows = [$this->row($shared)];
    $steps = $goal['steps'] ?? [];
    if (!is_array($steps)) {
      return $rows;
    }
    foreach ($steps as $step) {
      if (!is_array($step)) {
        continue;
      }
      $rows[] = $this->row([
        'row_type' => 'goal_step',
        'uuid' => $shared['uuid'],
        'goal_step' => $this->text($step['text'] ?? ''),
        'goal_step_done' => !empty($step['done']) ? '1' : '0',
      ]);
    }
    return $rows;
  }

  /**
   * Plans owned by this uid, newest first.
   *
   * The plans module may be absent. The list is empty in that case.
   *
   * @return list<array<string, mixed>>
   *   Plan documents.
   */
  private function plans(int $uid): array {
    if ($uid < 1 || !$this->entityTypeManager->hasDefinition('lifebalance_plan')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_plan');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    $loaded = $storage->loadMultiple($ids);
    $documents = [];
    foreach ($ids as $id) {
      $entity = $loaded[$id] ?? NULL;
      if (!$entity instanceof ContentEntityInterface || (int) $entity->get('uid')->getString() !== $uid) {
        continue;
      }
      $sectors = [];
      foreach ($entity->get('focus_sectors')->getValue() as $item) {
        $value = is_array($item) ? ($item['value'] ?? '') : '';
        if (is_string($value) && $value !== '') {
          $sectors[] = $value;
        }
      }
      $documents[] = [
        'uuid' => (string) $entity->uuid(),
        'status' => $this->fieldText($entity, 'status'),
        'focus_sectors' => $sectors,
        'starts' => $this->nullableInt($entity, 'starts') ?? 0,
        'ends' => $this->nullableInt($entity, 'ends') ?? 0,
        'created' => $this->nullableInt($entity, 'created') ?? 0,
      ];
    }
    return $documents;
  }

  /**
   * One CSV row for a plan. Goal text is not repeated here.
   *
   * @param array<string, mixed> $plan
   *   Plan document.
   *
   * @return list<string>
   *   A CSV record.
   */
  private function planRow(array $plan): array {
    $sectors = $plan['focus_sectors'] ?? [];
    $sectors = is_array($sectors) ? implode(',', $sectors) : '';
    return $this->row([
      'row_type' => 'plan',
      'uuid' => $this->text($plan['uuid'] ?? ''),
      'created' => $this->text($plan['created'] ?? ''),
      'plan_status' => $this->text($plan['status'] ?? ''),
      'plan_starts' => $this->text($plan['starts'] ?? ''),
      'plan_ends' => $this->text($plan['ends'] ?? ''),
      'plan_sectors' => $sectors,
    ]);
  }

  /**
   * String value of a field, or an empty string.
   */
  private function fieldText(ContentEntityInterface $entity, string $name): string {
    $value = $entity->get($name)->value;
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Non-empty string field, or NULL.
   */
  private function nullableText(ContentEntityInterface $entity, string $name): ?string {
    $value = $this->fieldText($entity, $name);
    return $value !== '' ? $value : NULL;
  }

  /**
   * Positive integer field, or NULL.
   */
  private function nullableInt(ContentEntityInterface $entity, string $name): ?int {
    $value = $entity->get($name)->value;
    if (!is_numeric($value) || (int) $value <= 0) {
      return NULL;
    }
    return (int) $value;
  }

  /**
   * Draft scores that are either unset or an integer from 0 to 10.
   *
   * @param mixed $scores
   *   Stored score map.
   *
   * @return list<array{sector_id: string, value: int|null}>
   *   Score rows.
   */
  private function draftScores(mixed $scores): array {
    if (!is_array($scores)) {
      return [];
    }
    $rows = [];
    foreach ($scores as $sector => $value) {
      if (!is_string($sector) || preg_match('/^[a-z0-9_]+$/', $sector) !== 1) {
        continue;
      }
      if ($value === NULL) {
        $rows[] = ['sector_id' => $sector, 'value' => NULL];
        continue;
      }
      if (is_int($value) && $value >= 0 && $value <= 10) {
        $rows[] = ['sector_id' => $sector, 'value' => $value];
      }
    }
    return $rows;
  }

  /**
   * Draft answers for a known code and a positive revision id.
   *
   * @param mixed $answers
   *   Stored answer map.
   *
   * @return list<array<string, mixed>>
   *   Answer rows.
   */
  private function draftAnswers(mixed $answers): array {
    if (!is_array($answers)) {
      return [];
    }
    $rows = [];
    foreach ($answers as $id => $answer) {
      if (!is_array($answer)) {
        continue;
      }
      $criterion_id = $this->positiveInt($id);
      $revision_id = $this->positiveInt($answer['rev'] ?? NULL);
      $value = $answer['a'] ?? '';
      if ($criterion_id === NULL || $revision_id === NULL || !is_string($value)) {
        continue;
      }
      if (!in_array($value, ['yes', 'partial', 'no', 'na'], TRUE)) {
        continue;
      }
      $wording = $this->wording($criterion_id, $revision_id);
      $rows[] = [
        'criterion_id' => $criterion_id,
        'key' => $wording['key'],
        'revision_id' => $revision_id,
        'text' => $wording['text'],
        'answer' => $value,
      ];
    }
    return $rows;
  }

  /**
   * Catalog key, and the text of this revision only.
   *
   * When the revision is missing, the key may still come from the current
   * criterion. The current wording is never substituted.
   *
   * @return array{key: string, text: string|null}
   *   Wording for the export.
   */
  private function wording(int $criterion_id, int $revision_id): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_criterion');
    if ($storage instanceof RevisionableStorageInterface) {
      $revision = $storage->loadRevision($revision_id);
      if ($revision instanceof Criterion
        && (int) $revision->id() === $criterion_id
        && (int) $revision->getRevisionId() === $revision_id
      ) {
        return [
          'key' => $revision->getCatalogKey(),
          'text' => $revision->getText(),
        ];
      }
    }
    $current = $storage->load($criterion_id);
    $key = '';
    if ($current instanceof Criterion && (int) $current->id() === $criterion_id) {
      $key = $current->getCatalogKey();
    }
    return ['key' => $key, 'text' => NULL];
  }

  /**
   * Canonical positive integer, or NULL.
   *
   * @param mixed $value
   *   Candidate identifier.
   */
  private function positiveInt(mixed $value): ?int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
      return NULL;
    }
    $id = (int) $value;
    return (string) $id === $value ? $id : NULL;
  }

  /**
   * Draft rows. A note-only draft still produces one row.
   *
   * @param array<string, mixed>|null $draft
   *   Draft document.
   *
   * @return list<list<string>>
   *   CSV records.
   */
  private function draftRows(?array $draft): array {
    if ($draft === NULL) {
      return [];
    }
    $shared = [
      'draft_updated' => $this->text($draft['updated'] ?? 0),
      'note' => $this->text($draft['note'] ?? ''),
    ];
    $scores = $draft['scores'] ?? [];
    $answers = $draft['answers'] ?? [];
    if (!is_array($scores) || !is_array($answers)) {
      return [$this->row(['row_type' => 'draft'] + $shared)];
    }
    $rows = [];
    if ($scores === [] && $answers === []) {
      $rows[] = $this->row(['row_type' => 'draft'] + $shared);
    }
    foreach ($scores as $score) {
      if (!is_array($score)) {
        continue;
      }
      $value = $score['value'] ?? NULL;
      $rows[] = $this->row([
        'row_type' => 'draft_score',
        'sector_id' => $this->text($score['sector_id'] ?? ''),
        'value' => $value === NULL ? '' : $this->text($value),
      ] + $shared);
    }
    foreach ($answers as $answer) {
      if (!is_array($answer)) {
        continue;
      }
      $text = $answer['text'] ?? '';
      $rows[] = $this->row([
        'row_type' => 'draft_answer',
        'criterion_id' => $this->text($answer['criterion_id'] ?? ''),
        'criterion_key' => $this->text($answer['key'] ?? ''),
        'revision_id' => $this->text($answer['revision_id'] ?? ''),
        'revision_text' => is_string($text) ? $text : '',
        'answer' => $this->text($answer['answer'] ?? ''),
      ] + $shared);
    }
    return $rows;
  }

  /**
   * Snapshot rows. A snapshot with no rows still keeps its note.
   *
   * @param array<string, mixed> $snapshot
   *   Snapshot document.
   *
   * @return list<list<string>>
   *   CSV records.
   */
  private function snapshotRows(array $snapshot): array {
    $formula = $snapshot['formula_version'] ?? '';
    $shared = [
      'uuid' => $this->text($snapshot['uuid'] ?? ''),
      'created' => $this->text($snapshot['created'] ?? ''),
      'note' => $this->text($snapshot['note'] ?? ''),
      'assessment_mode' => $this->text($snapshot['assessment_mode'] ?? ''),
      'formula_version' => is_string($formula) ? $formula : '',
      'catalog_version' => $this->text($snapshot['catalog_version'] ?? ''),
    ];
    $scores = $snapshot['scores'] ?? [];
    $answers = $snapshot['answers'] ?? [];
    if (!is_array($scores) || !is_array($answers)) {
      return [$this->row(['row_type' => 'snapshot'] + $shared)];
    }
    $rows = [];
    if ($scores === [] && $answers === []) {
      $rows[] = $this->row(['row_type' => 'snapshot'] + $shared);
    }
    foreach ($scores as $score) {
      if (!is_array($score)) {
        continue;
      }
      $guide = $score['guide'] ?? NULL;
      $rows[] = $this->row([
        'row_type' => 'snapshot_score',
        'sector_id' => $this->text($score['sector_id'] ?? ''),
        'value' => $this->text($score['value'] ?? ''),
        'guide' => $guide === NULL ? '' : $this->text($guide),
        'answered' => $this->text($score['answered'] ?? ''),
      ] + $shared);
    }
    foreach ($answers as $answer) {
      if (!is_array($answer)) {
        continue;
      }
      $text = $answer['text'] ?? '';
      $rows[] = $this->row([
        'row_type' => 'snapshot_answer',
        'criterion_id' => $this->text($answer['criterion_id'] ?? ''),
        'criterion_key' => $this->text($answer['key'] ?? ''),
        'revision_id' => $this->text($answer['revision_id'] ?? ''),
        'revision_text' => is_string($text) ? $text : '',
        'answer' => $this->text($answer['answer'] ?? ''),
      ] + $shared);
    }
    return $rows;
  }

  /**
   * String form of an export value. NULL and other types become empty.
   *
   * @param mixed $value
   *   Export value.
   */
  private function text(mixed $value): string {
    if (is_string($value)) {
      return $value;
    }
    if (is_int($value)) {
      return (string) $value;
    }
    return '';
  }

  /**
   * Encodes records as CSV.
   *
   * @param list<list<string>> $rows
   *   Records, including the header.
   */
  private function encode(array $rows): string {
    $handle = fopen('php://temp', 'w+');
    if ($handle === FALSE) {
      throw new \RuntimeException('The export could not be opened.');
    }
    foreach ($rows as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    if ($csv === FALSE) {
      throw new \RuntimeException('The export could not be read.');
    }
    return $csv;
  }

  /**
   * One CSV record with formula-leading cells neutralized.
   *
   * @param array<string, string> $values
   *   Column values.
   *
   * @return list<string>
   *   Cells in column order.
   */
  private function row(array $values): array {
    $row = [];
    foreach (self::COLUMNS as $column) {
      $row[] = $this->cell($values[$column] ?? '');
    }
    return $row;
  }

  /**
   * Prefixes a cell that a spreadsheet would treat as a formula.
   */
  private function cell(string $value): string {
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
      return "'" . $value;
    }
    return $value;
  }

}
