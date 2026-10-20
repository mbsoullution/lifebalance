<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

/**
 * One suggestion to show. It is not stored.
 */
final class Suggestion {

  /**
   * Constructs one suggestion.
   *
   * @param string $key
   *   Stable catalog key.
   * @param string $title
   *   Short title.
   * @param string $body
   *   Recommendation text.
   * @param list<string> $steps
   *   Checklist steps.
   * @param bool $healthNote
   *   Whether the health note is shown.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $title,
    public readonly string $body,
    public readonly array $steps,
    public readonly bool $healthNote,
  ) {}

}
