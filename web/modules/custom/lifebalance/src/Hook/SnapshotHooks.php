<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\SnapshotPreparer;

/**
 * Derives snapshot fields before save and drops the owner's list cache.
 */
final class SnapshotHooks {

  public function __construct(
    private readonly SnapshotPreparer $preparer,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Rewrites the guide from the answers, then rejects an invalid snapshot.
   */
  #[Hook('lifebalance_snapshot_presave')]
  public function presave(Snapshot $snapshot): void {
    $this->preparer->prepare($snapshot);
    $violations = $snapshot->validate();
    if ($violations->count() === 0) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    throw new EntityStorageException(implode(' ', $messages));
  }

  /**
   * Invalidates the owner's snapshot list.
   */
  #[Hook('lifebalance_snapshot_insert')]
  #[Hook('lifebalance_snapshot_update')]
  #[Hook('lifebalance_snapshot_delete')]
  public function invalidateList(Snapshot $snapshot): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'lifebalance_snapshot_list:' . $snapshot->getOwnerId(),
    ]);
  }

}
