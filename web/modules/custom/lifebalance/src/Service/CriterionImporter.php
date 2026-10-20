<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\CriterionStorage;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\SectorRepository;
use Psr\Log\LoggerInterface;

/**
 * Imports the criteria catalog. The file is the source for wording and order.
 *
 * Status, origin, and in_formula are set only when a key is created, so an
 * archived criterion stays archived. Subtopic is copied only when the file
 * contains that key, so a catalog without subtopics leaves an edited value.
 * A missing key is reported and left in place. The YAML field "original" is
 * not stored.
 */
final class CriterionImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SectorRepository $sectorRepository,
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
    return $this->appRoot . '/' . $this->moduleExtensionList->getPath('lifebalance') . '/data/criteria.v1.yml';
  }

  /**
   * Imports a catalog file. An empty path uses the shipped catalog.
   */
  public function import(?string $file = NULL): CriterionImportResult {
    $path = ($file === NULL || $file === '') ? $this->defaultPath() : $file;
    $rows = $this->readRows($path);
    $counts = $this->writeRows($rows);
    $warned = $this->warnMissing(array_map(static fn (CriterionCatalogRow $row): string => $row->key, $rows));
    return new CriterionImportResult(
      $counts['created'],
      $counts['updated'],
      $counts['unchanged'],
      $warned,
    );
  }

  /**
   * Validates the whole file before anything is written.
   *
   * @return list<\Drupal\lifebalance\Service\CriterionCatalogRow>
   *   Catalog rows.
   */
  private function readRows(string $path): array {
    if (!is_file($path)) {
      throw new \InvalidArgumentException(sprintf('Criteria file not found: %s', $path));
    }
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \InvalidArgumentException(sprintf('Criteria file could not be read: %s', $path));
    }
    $decoded = Yaml::decode($contents);
    if (!is_array($decoded)) {
      throw new \InvalidArgumentException('Criteria file must be a mapping.');
    }
    $unknown = array_diff(array_keys($decoded), ['catalog_version', 'langcode', 'criteria']);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Criteria file has unexpected keys: ' . implode(', ', $unknown));
    }
    if ((int) ($decoded['catalog_version'] ?? 0) !== 1) {
      throw new \InvalidArgumentException('Criteria catalog_version must be 1.');
    }
    $criteria = $decoded['criteria'] ?? NULL;
    if (!is_array($criteria) || $criteria === []) {
      throw new \InvalidArgumentException('Criteria file must contain a criteria list.');
    }

    $sectors = array_keys($this->sectorRepository->loadAll());
    $rows = [];
    $seen = [];
    foreach ($criteria as $index => $row) {
      if (!is_array($row)) {
        throw new \InvalidArgumentException(sprintf('Criteria row %s must be a mapping.', (string) $index));
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
  private function row(array $row, array $sectors, array &$seen): CriterionCatalogRow {
    $unknown = array_diff(array_keys($row), [
      'key',
      'sector',
      'tier',
      'weight',
      'text',
      'help',
      'subtopic',
      'original',
    ]);
    if ($unknown !== []) {
      throw new \InvalidArgumentException('Criteria row has unexpected keys: ' . implode(', ', $unknown));
    }

    $key = $row['key'] ?? NULL;
    if (!is_string($key) || !preg_match(Criterion::KEY_PATTERN, $key)) {
      throw new \InvalidArgumentException('A criteria row has an invalid key.');
    }
    if (isset($seen[$key])) {
      throw new \InvalidArgumentException(sprintf('Duplicate criterion key "%s" in the catalog file.', $key));
    }
    $seen[$key] = TRUE;

    $sector = $row['sector'] ?? NULL;
    if (!is_string($sector) || !in_array($sector, $sectors, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown sector "%s" for criterion "%s".', is_scalar($sector) ? (string) $sector : '', $key));
    }

    $tier = $row['tier'] ?? NULL;
    if (!is_string($tier) || !isset(Criterion::tierLabels()[$tier])) {
      throw new \InvalidArgumentException(sprintf('Invalid tier for criterion "%s".', $key));
    }

    $weight = $row['weight'] ?? NULL;
    if (is_string($weight) && preg_match('/^-?\d+$/', $weight)) {
      $weight = (int) $weight;
    }
    if (!is_int($weight)) {
      throw new \InvalidArgumentException(sprintf('Invalid weight for criterion "%s".', $key));
    }

    $text = $row['text'] ?? NULL;
    if (!is_string($text) || $text === '' || mb_strlen($text) > 255) {
      throw new \InvalidArgumentException(sprintf('Invalid text for criterion "%s".', $key));
    }

    $help = $row['help'] ?? NULL;
    if ($help !== NULL && !is_string($help)) {
      throw new \InvalidArgumentException(sprintf('Invalid help for criterion "%s".', $key));
    }
    if ($help === '') {
      $help = NULL;
    }

    $subtopic_provided = array_key_exists('subtopic', $row);
    $subtopic = '';
    if ($subtopic_provided) {
      $raw_subtopic = $row['subtopic'];
      if ($raw_subtopic === NULL) {
        $raw_subtopic = '';
      }
      if (!is_string($raw_subtopic) || mb_strlen(trim($raw_subtopic)) > 128) {
        throw new \InvalidArgumentException(sprintf('Invalid subtopic for criterion "%s".', $key));
      }
      $subtopic = trim($raw_subtopic);
    }

    return new CriterionCatalogRow($key, $sector, $tier, $weight, $text, $help, $subtopic_provided, $subtopic);
  }

  /**
   * Creates and updates criteria inside one database transaction.
   *
   * @param list<\Drupal\lifebalance\Service\CriterionCatalogRow> $rows
   *   Valid catalog rows.
   *
   * @return array{created: int, updated: int, unchanged: int}
   *   Write counts.
   */
  private function writeRows(array $rows): array {
    $transaction = $this->connection->startTransaction();
    try {
      $storage = $this->storage();
      $existing = $this->loadExisting($storage, array_map(static fn (CriterionCatalogRow $row): string => $row->key, $rows));
      $created = 0;
      $updated = 0;
      $unchanged = 0;
      foreach ($rows as $row) {
        $entity = $existing[$row->key] ?? NULL;
        if (!$entity instanceof Criterion) {
          $storage->create([
            'key' => $row->key,
            'sector' => $row->sector,
            'tier' => $row->tier,
            'text' => $row->text,
            'help' => $row->help,
            'weight' => $row->weight,
            'status' => Criterion::STATUS_PUBLISHED,
            'origin' => Criterion::ORIGIN_SYSTEM,
            'in_formula' => TRUE,
            'subtopic' => $row->subtopicProvided ? $row->subtopic : NULL,
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
   * Copies catalog fields onto an existing criterion.
   *
   * Status, origin, and in_formula stay as they are. Subtopic stays unless
   * the catalog row includes that key.
   */
  private function applyRow(Criterion $entity, CriterionCatalogRow $row): bool {
    $changed = FALSE;
    if ($entity->getText() !== $row->text) {
      $entity->set('text', $row->text);
      $changed = TRUE;
    }
    $help = $row->help ?? '';
    if ($entity->getHelp() !== $help) {
      $entity->set('help', $row->help);
      $changed = TRUE;
    }
    if ($entity->getWeight() !== $row->weight) {
      $entity->set('weight', $row->weight);
      $changed = TRUE;
    }
    if ($entity->getSectorId() !== $row->sector) {
      $entity->set('sector', $row->sector);
      $changed = TRUE;
    }
    if ($entity->getTier() !== $row->tier) {
      $entity->set('tier', $row->tier);
      $changed = TRUE;
    }
    if ($row->subtopicProvided && $entity->getSubtopic() !== $row->subtopic) {
      $entity->set('subtopic', $row->subtopic === '' ? NULL : $row->subtopic);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Warns about system keys that the file no longer contains.
   *
   * @param list<string> $file_keys
   *   Keys present in the file.
   */
  private function warnMissing(array $file_keys): int {
    $storage = $this->storage();
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('origin', Criterion::ORIGIN_SYSTEM);
    if ($file_keys !== []) {
      $query->condition('key', $file_keys, 'NOT IN');
    }
    $ids = $query->execute();
    $warned = 0;
    foreach ($storage->loadMultiple($ids) as $entity) {
      if (!$entity instanceof Criterion) {
        continue;
      }
      $this->logger->warning('Criteria catalog is missing system key {key}. The criterion was left in place.', [
        'key' => $entity->getCatalogKey(),
      ]);
      $warned++;
    }
    return $warned;
  }

  /**
   * Loads criteria for the given catalog keys.
   *
   * @param \Drupal\lifebalance\CriterionStorage $storage
   *   Criterion storage.
   * @param list<string> $keys
   *   Catalog keys to load.
   *
   * @return array<string, \Drupal\lifebalance\Entity\Criterion>
   *   Criteria keyed by catalog key.
   */
  private function loadExisting(CriterionStorage $storage, array $keys): array {
    if ($keys === []) {
      return [];
    }
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $keys, 'IN')
      ->execute();
    $existing = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof Criterion) {
        $existing[$entity->getCatalogKey()] = $entity;
      }
    }
    return $existing;
  }

  /**
   * Criterion storage.
   */
  private function storage(): CriterionStorage {
    $storage = $this->entityTypeManager->getStorage('lifebalance_criterion');
    if (!$storage instanceof CriterionStorage) {
      throw new \LogicException('Criterion storage is not available.');
    }
    return $storage;
  }

}
