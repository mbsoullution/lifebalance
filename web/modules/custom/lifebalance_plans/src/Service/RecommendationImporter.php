<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\lifebalance_plans\RecommendationStorage;
use Psr\Log\LoggerInterface;

/**
 * Imports the recommendation catalog. The file is the source for wording.
 *
 * New rows are drafts. Status is not taken from the file and is not changed
 * on update, so a published recommendation stays published. A missing key is
 * reported and left in place. A change of title, body, or steps creates a
 * revision.
 */
final class RecommendationImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SectorRepository $sectorRepository,
    private readonly CriterionRepository $criterionRepository,
    private readonly LoggerInterface $logger,
    private readonly AccountInterface $currentUser,
    private readonly Connection $connection,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly string $appRoot,
  ) {}

  /**
   * Absolute path of the shipped catalog.
   */
  public function defaultPath(): string {
    return $this->appRoot . '/' . $this->moduleExtensionList->getPath('lifebalance_plans') . '/data/recommendations.v1.yml';
  }

  /**
   * Imports a catalog file. An empty path uses the shipped catalog.
   */
  public function import(?string $file = NULL): RecommendationImportResult {
    $path = ($file === NULL || $file === '') ? $this->defaultPath() : $file;
    $rows = $this->readRows($path);
    $counts = $this->writeRows($rows);
    $warned = $this->warnMissing(array_map(static fn (RecommendationCatalogRow $row): string => $row->key, $rows));
    return new RecommendationImportResult(
      $counts['created'],
      $counts['updated'],
      $counts['unchanged'],
      $warned,
    );
  }

  /**
   * Validates the whole file before anything is written.
   *
   * @return list<\Drupal\lifebalance_plans\Service\RecommendationCatalogRow>
   *   Catalog rows.
   */
  private function readRows(string $path): array {
    if (!is_file($path)) {
      throw new \InvalidArgumentException(sprintf('Recommendation file not found: %s', $path));
    }
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \InvalidArgumentException(sprintf('Recommendation file could not be read: %s', $path));
    }
    $decoded = Yaml::decode($contents);
    if (!is_array($decoded)) {
      throw new \InvalidArgumentException('Recommendation file must be a mapping.');
    }
    $unknown = array_diff(array_keys($decoded), ['catalog_version', 'langcode', 'recommendations']);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Recommendation file has unexpected keys: ' . implode(', ', $unknown));
    }
    if ((int) ($decoded['catalog_version'] ?? 0) !== 1) {
      throw new \InvalidArgumentException('Recommendation catalog_version must be 1.');
    }
    $recommendations = $decoded['recommendations'] ?? NULL;
    if (!is_array($recommendations)) {
      throw new \InvalidArgumentException('Recommendation file must contain a recommendations list.');
    }

    $sectors = array_keys($this->sectorRepository->loadAll());
    $rows = [];
    $seen = [];
    foreach ($recommendations as $index => $row) {
      if (!is_array($row)) {
        throw new \InvalidArgumentException(sprintf('Recommendation row %s must be a mapping.', (string) $index));
      }
      $rows[] = $this->row($row, $sectors, $seen);
    }
    return $rows;
  }

  /**
   * Builds one validated catalog row.
   *
   * @param array<mixed> $row
   *   One YAML mapping.
   * @param list<string> $sectors
   *   Known sector IDs.
   * @param array<string, true> $seen
   *   Keys already accepted from this file.
   */
  private function row(array $row, array $sectors, array &$seen): RecommendationCatalogRow {
    $unknown = array_diff(array_keys($row), [
      'key',
      'sector',
      'trigger_criteria',
      'trigger_answers',
      'title',
      'body',
      'steps',
      'type',
      'effort',
      'safety',
      'tier',
      'weight',
    ]);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Recommendation row has unexpected keys: ' . implode(', ', $unknown));
    }

    $key = $row['key'] ?? NULL;
    if (!is_string($key) || !preg_match(Criterion::KEY_PATTERN, $key)) {
      throw new \InvalidArgumentException('A recommendation row has an invalid key.');
    }
    if (isset($seen[$key])) {
      throw new \InvalidArgumentException(sprintf('Duplicate recommendation key "%s" in the catalog file.', $key));
    }
    $seen[$key] = TRUE;

    $sector = $row['sector'] ?? NULL;
    if (!is_string($sector) || !in_array($sector, $sectors, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown sector "%s" for recommendation "%s".', is_scalar($sector) ? (string) $sector : '', $key));
    }

    $triggers = $this->stringList($row['trigger_criteria'] ?? NULL, $key, 'trigger_criteria');
    if ($triggers === []) {
      throw new \InvalidArgumentException(sprintf('Recommendation "%s" needs at least one trigger criterion.', $key));
    }
    foreach ($triggers as $trigger) {
      if (!preg_match(Criterion::KEY_PATTERN, $trigger)) {
        throw new \InvalidArgumentException(sprintf('Recommendation "%s" has an invalid trigger criterion.', $key));
      }
      $criterion = $this->criterionRepository->loadByKey($trigger);
      if (!$criterion instanceof Criterion) {
        throw new \InvalidArgumentException(sprintf('Unknown criterion "%s" for recommendation "%s".', $trigger, $key));
      }
      if ($criterion->getSectorId() !== $sector) {
        throw new \InvalidArgumentException(sprintf('Criterion "%s" is not in sector "%s".', $trigger, $sector));
      }
    }

    $answers = $this->stringList($row['trigger_answers'] ?? NULL, $key, 'trigger_answers');
    $allowed_answers = array_keys(Recommendation::answerLabels());
    if ($answers === [] || array_diff($answers, $allowed_answers) !== []) {
      throw new \InvalidArgumentException(sprintf('Invalid trigger answers for recommendation "%s".', $key));
    }

    $title = $row['title'] ?? NULL;
    if (!is_string($title) || $title === '' || mb_strlen($title) > 80) {
      throw new \InvalidArgumentException(sprintf('Invalid title for recommendation "%s".', $key));
    }
    $body = $row['body'] ?? NULL;
    if (!is_string($body) || trim($body) === '') {
      throw new \InvalidArgumentException(sprintf('Invalid body for recommendation "%s".', $key));
    }
    $steps = $this->stringList($row['steps'] ?? NULL, $key, 'steps');
    if (count($steps) < 1 || count($steps) > 5) {
      throw new \InvalidArgumentException(sprintf('Recommendation "%s" needs 1 to 5 steps.', $key));
    }
    foreach ($steps as $step) {
      if (mb_strlen($step) > 255) {
        throw new \InvalidArgumentException(sprintf('A step for recommendation "%s" is too long.', $key));
      }
    }

    $type = $this->choice($row['type'] ?? NULL, array_keys(Recommendation::typeLabels()), $key, 'type');
    $effort = $this->choice($row['effort'] ?? NULL, array_keys(Recommendation::effortLabels()), $key, 'effort');
    $safety = $this->choice($row['safety'] ?? NULL, array_keys(Recommendation::safetyLabels()), $key, 'safety');
    $tier = $this->choice($row['tier'] ?? NULL, array_keys(Recommendation::tierLabels()), $key, 'tier');

    $weight = $row['weight'] ?? NULL;
    if (is_string($weight) && preg_match('/^-?\d+$/', $weight)) {
      $weight = (int) $weight;
    }
    if (!is_int($weight)) {
      throw new \InvalidArgumentException(sprintf('Invalid weight for recommendation "%s".', $key));
    }

    return new RecommendationCatalogRow(
      $key,
      $sector,
      $triggers,
      $answers,
      $title,
      trim($body),
      $steps,
      $type,
      $effort,
      $safety,
      $tier,
      $weight,
    );
  }

  /**
   * Creates and updates recommendations inside one database transaction.
   *
   * @param list<\Drupal\lifebalance_plans\Service\RecommendationCatalogRow> $rows
   *   Valid catalog rows.
   *
   * @return array{created: int, updated: int, unchanged: int}
   *   Write counts.
   */
  private function writeRows(array $rows): array {
    $transaction = $this->connection->startTransaction();
    try {
      $storage = $this->storage();
      $existing = $this->loadExisting($storage, array_map(static fn (RecommendationCatalogRow $row): string => $row->key, $rows));
      $created = 0;
      $updated = 0;
      $unchanged = 0;
      foreach ($rows as $row) {
        $entity = $existing[$row->key] ?? NULL;
        if (!$entity instanceof Recommendation) {
          $storage->create($this->values($row) + [
            'status' => Recommendation::STATUS_DRAFT,
            'uid' => (int) $this->currentUser->id(),
          ])->save();
          $created++;
          continue;
        }
        if (!$this->applyRow($entity, $row)) {
          $unchanged++;
          continue;
        }
        $entity->setNewRevision(FALSE);
        $entity->save();
        $updated++;
      }
      return [
        'created' => $created,
        'updated' => $updated,
        'unchanged' => $unchanged,
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Field values shared by create and update.
   *
   * @return array<string, mixed>
   *   Entity values, without status or owner.
   */
  private function values(RecommendationCatalogRow $row): array {
    return [
      'key' => $row->key,
      'sector' => $row->sector,
      'trigger_criteria' => $row->triggerCriteria,
      'trigger_answers' => $row->triggerAnswers,
      'title' => $row->title,
      'body' => [
        'value' => $row->body,
        'format' => Recommendation::BODY_FORMAT,
      ],
      'steps' => $row->steps,
      'type' => $row->type,
      'effort' => $row->effort,
      'safety' => $row->safety,
      'tier' => $row->tier,
      'weight' => $row->weight,
    ];
  }

  /**
   * Copies catalog fields onto an existing recommendation.
   *
   * Status stays as it is.
   */
  private function applyRow(Recommendation $entity, RecommendationCatalogRow $row): bool {
    $changed = FALSE;
    if ($entity->getSectorId() !== $row->sector) {
      $entity->set('sector', $row->sector);
      $changed = TRUE;
    }
    if ($entity->getTriggerCriteria() !== $row->triggerCriteria) {
      $entity->set('trigger_criteria', $row->triggerCriteria);
      $changed = TRUE;
    }
    if ($entity->getTriggerAnswers() !== $row->triggerAnswers) {
      $entity->set('trigger_answers', $row->triggerAnswers);
      $changed = TRUE;
    }
    if ($entity->getTitle() !== $row->title) {
      $entity->set('title', $row->title);
      $changed = TRUE;
    }
    if ($entity->getBody() !== $row->body) {
      $entity->set('body', [
        'value' => $row->body,
        'format' => Recommendation::BODY_FORMAT,
      ]);
      $changed = TRUE;
    }
    if ($entity->getSteps() !== $row->steps) {
      $entity->set('steps', $row->steps);
      $changed = TRUE;
    }
    if ($entity->getType() !== $row->type) {
      $entity->set('type', $row->type);
      $changed = TRUE;
    }
    if ($entity->getEffort() !== $row->effort) {
      $entity->set('effort', $row->effort);
      $changed = TRUE;
    }
    if ($entity->getSafety() !== $row->safety) {
      $entity->set('safety', $row->safety);
      $changed = TRUE;
    }
    if ($entity->getTier() !== $row->tier) {
      $entity->set('tier', $row->tier);
      $changed = TRUE;
    }
    if ($entity->getWeight() !== $row->weight) {
      $entity->set('weight', $row->weight);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Warns about keys that the file no longer contains.
   *
   * @param list<string> $file_keys
   *   Keys present in the file.
   */
  private function warnMissing(array $file_keys): int {
    $storage = $this->storage();
    $query = $storage->getQuery()->accessCheck(TRUE);
    if ($file_keys !== []) {
      $query->condition('key', $file_keys, 'NOT IN');
    }
    $ids = $query->execute();
    $warned = 0;
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof Recommendation) {
        continue;
      }
      $this->logger->warning('Recommendation catalog is missing key {key}. The recommendation was left in place.', [
        'key' => $entity->getCatalogKey(),
      ]);
      $warned++;
    }
    return $warned;
  }

  /**
   * Loads recommendations for the given catalog keys.
   *
   * @param \Drupal\lifebalance_plans\RecommendationStorage $storage
   *   Recommendation storage.
   * @param list<string> $keys
   *   Catalog keys to load.
   *
   * @return array<string, \Drupal\lifebalance_plans\Entity\Recommendation>
   *   Recommendations keyed by catalog key.
   */
  private function loadExisting(RecommendationStorage $storage, array $keys): array {
    if ($keys === []) {
      return [];
    }
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $keys, 'IN')
      ->execute();
    $existing = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof Recommendation) {
        $existing[$entity->getCatalogKey()] = $entity;
      }
    }
    return $existing;
  }

  /**
   * A list of non-empty strings from one YAML value.
   *
   * @param mixed $value
   *   Raw YAML value.
   * @param string $key
   *   Catalog key, used in the error.
   * @param string $field
   *   Field name, used in the error.
   *
   * @return list<string>
   *   The list.
   */
  private function stringList(mixed $value, string $key, string $field): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException(sprintf('Recommendation "%s" field "%s" must be a list.', $key, $field));
    }
    $items = [];
    foreach ($value as $item) {
      if (!is_string($item) || trim($item) === '') {
        throw new \InvalidArgumentException(sprintf('Recommendation "%s" field "%s" must contain text.', $key, $field));
      }
      $items[] = trim($item);
    }
    return $items;
  }

  /**
   * One allowed choice.
   *
   * @param mixed $value
   *   Raw YAML value.
   * @param list<string> $allowed
   *   Stored values.
   * @param string $key
   *   Catalog key, used in the error.
   * @param string $field
   *   Field name, used in the error.
   */
  private function choice(mixed $value, array $allowed, string $key, string $field): string {
    if (!is_string($value) || !in_array($value, $allowed, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Invalid %s for recommendation "%s".', $field, $key));
    }
    return $value;
  }

  /**
   * Recommendation storage.
   */
  private function storage(): RecommendationStorage {
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    if (!$storage instanceof RecommendationStorage) {
      throw new \LogicException('Recommendation storage is not available.');
    }
    return $storage;
  }

}
