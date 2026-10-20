<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Stable codes for snapshot validation messages.
 */
final class SnapshotProblem {

  public const MISSING_SECTOR = 'missing_sector';

  public const DUPLICATE_SECTOR = 'duplicate_sector';

  public const UNKNOWN_SECTOR = 'unknown_sector';

  public const DUPLICATE_ANSWER = 'duplicate_answer';

  public const STALE_OR_UNPUBLISHED = 'stale_or_unpublished';

  public const INACTIVE_SECTOR_ANSWER = 'inactive_sector_answer';

  public const PREMIUM_DENIED = 'premium_denied';

  public const NOTE_TOO_LONG = 'note_too_long';

}
