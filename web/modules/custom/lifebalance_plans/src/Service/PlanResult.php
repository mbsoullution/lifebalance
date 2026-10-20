<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\lifebalance_plans\Entity\Plan;

/**
 * Outcome of trying to start or close a plan. The form translates the code.
 */
final class PlanResult {

  public const SAVED = 'saved';

  public const PREMIUM = 'premium';

  public const ACTIVE = 'active';

  public const LIMIT = 'limit';

  public const INVALID = 'invalid';

  public function __construct(
    public readonly string $code,
    public readonly ?Plan $plan = NULL,
  ) {}

}
