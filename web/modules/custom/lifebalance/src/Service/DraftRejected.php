<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * The draft body was refused. The message is a status label, never user data.
 */
final class DraftRejected extends \RuntimeException {

  /**
   * Malformed body, unknown sector, or an answer that cannot be stored.
   */
  public const INVALID = 400;

  /**
   * A premium answer arrived without entitlement.
   */
  public const FORBIDDEN = 403;

  public function __construct(public readonly int $statusCode) {
    parent::__construct($statusCode === self::FORBIDDEN ? 'premium' : 'invalid');
  }

}
