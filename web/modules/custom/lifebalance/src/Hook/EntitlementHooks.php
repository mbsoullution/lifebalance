<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Drops the owner's entitlement cache when a record changes.
 */
final class EntitlementHooks {

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Invalidates lifebalance_entitlement:{uid}.
   */
  #[Hook('lifebalance_entitlement_insert')]
  #[Hook('lifebalance_entitlement_update')]
  #[Hook('lifebalance_entitlement_delete')]
  public function invalidate(Entitlement $entitlement): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'lifebalance_entitlement:' . $entitlement->getOwnerId(),
    ]);
  }

}
