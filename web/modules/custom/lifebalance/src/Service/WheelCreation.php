<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserDataInterface;

/**
 * Records the moment a person creates their wheel.
 *
 * The mark lives in user.data. A second call keeps the first timestamp.
 */
final class WheelCreation {

  public const MODULE = 'lifebalance';

  public const NAME = 'wheel_created';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly Clock $clock,
  ) {}

  /**
   * Stored creation time, or NULL when the wheel has not been created.
   */
  public function createdAt(AccountInterface $account): ?int {
    return $this->timestampForUid((int) $account->id());
  }

  /**
   * Stored creation time for a uid.
   *
   * User data returns scalar values as strings.
   */
  public function timestampForUid(int $uid): ?int {
    if ($uid < 1) {
      return NULL;
    }
    $stored = $this->userData->get(self::MODULE, $uid, self::NAME);
    if (is_int($stored) && $stored > 0) {
      return $stored;
    }
    if (is_string($stored) && ctype_digit($stored)) {
      $timestamp = (int) $stored;
      return $timestamp > 0 ? $timestamp : NULL;
    }
    return NULL;
  }

  /**
   * Stores the creation time once and returns it.
   */
  public function ensure(AccountInterface $account): int {
    $existing = $this->createdAt($account);
    if ($existing !== NULL) {
      return $existing;
    }
    $uid = (int) $account->id();
    if ($uid < 1) {
      throw new \InvalidArgumentException(
        'A wheel needs an account.',
      );
    }
    $now = $this->clock->now();
    $this->userData->set(self::MODULE, $uid, self::NAME, $now);
    $stored = $this->createdAt($account);
    return $stored ?? $now;
  }

}
