<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\lifebalance_plans\Entity\Goal;

/**
 * Outcome of trying to add a goal. The form translates the code.
 */
final class GoalResult {

  public const SAVED = 'saved';

  public const PREMIUM = 'premium';

  public const LIMIT = 'limit';

  public const MISSING = 'missing';

  public const SENSITIVE = 'sensitive';

  public const UNPUBLISHED = 'unpublished';

  public const DUPLICATE = 'duplicate';

  public const INVALID = 'invalid';

  public function __construct(
    public readonly string $code,
    public readonly ?Goal $goal = NULL,
  ) {}

}
