<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * One row of the criteria catalog after validation.
 */
final class CriterionCatalogRow {

  public function __construct(
    public readonly string $key,
    public readonly string $sector,
    public readonly string $tier,
    public readonly int $weight,
    public readonly string $text,
    public readonly ?string $help,
    public readonly bool $subtopicProvided,
    public readonly string $subtopic,
  ) {}

}
