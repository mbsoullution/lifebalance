<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lifebalance\Entity\Sector;

/**
 * Loads life-area sectors.
 */
final class SectorRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Active sectors ordered by weight, then ID.
   *
   * @return array<string, \Drupal\lifebalance\Entity\Sector>
   *   Sectors keyed by ID.
   */
  public function loadActive(): array {
    return $this->load(TRUE);
  }

  /**
   * All sectors ordered by weight, then ID.
   *
   * @return array<string, \Drupal\lifebalance\Entity\Sector>
   *   Sectors keyed by ID.
   */
  public function loadAll(): array {
    return $this->load(FALSE);
  }

  /**
   * Loads sectors, optionally limited to active ones.
   *
   * @return array<string, \Drupal\lifebalance\Entity\Sector>
   *   Sectors keyed by ID.
   */
  private function load(bool $active_only): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_sector');
    $query = $storage->getQuery()->accessCheck(TRUE);
    if ($active_only) {
      $query->condition('status', TRUE);
    }
    $ids = $query->execute();
    $sectors = [];
    foreach ($storage->loadMultiple($ids) as $id => $sector) {
      if ($sector instanceof Sector) {
        $sectors[(string) $id] = $sector;
      }
    }
    uasort($sectors, static function (Sector $a, Sector $b): int {
      $weight = $a->getWeight() <=> $b->getWeight();
      return $weight !== 0 ? $weight : strcmp((string) $a->id(), (string) $b->id());
    });
    return $sectors;
  }

}
