<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Counts from one criteria import.
 */
final class CriterionImportResult {

  public function __construct(
    public readonly int $created,
    public readonly int $updated,
    public readonly int $unchanged,
    public readonly int $warned,
  ) {}

}
