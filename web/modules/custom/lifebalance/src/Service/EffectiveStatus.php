<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Access summary for one account at one instant.
 */
final class EffectiveStatus {

  public const FREE = 'free';

  public const TRIAL = 'trial';

  public const PREMIUM = 'premium';

  public const EXPIRED = 'expired';

  /**
   * Stores the summary.
   *
   * @param string $status
   *   One of free, trial, premium, or expired.
   * @param list<string> $sources
   *   Sources of the records that are active at this instant.
   * @param int|null $ends
   *   Latest end, or NULL when access does not expire.
   */
  public function __construct(
    public readonly string $status,
    public readonly array $sources,
    public readonly ?int $ends,
  ) {}

}
