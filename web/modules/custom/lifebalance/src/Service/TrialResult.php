<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\lifebalance\Entity\Entitlement;

/**
 * Outcome of a self-serve trial. The usage row decides the race.
 */
final class TrialResult {

  public const SAVED = 'saved';

  public const DISABLED = 'disabled';

  public const NO_SNAPSHOT = 'no_snapshot';

  public const USED = 'used';

  public function __construct(
    public readonly string $code,
    public readonly ?Entitlement $entitlement = NULL,
  ) {}

}
