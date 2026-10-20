<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * The only clock the module reads.
 *
 * Tests store an override in state so a later HTTP request sees the same
 * instant. Production leaves that state empty and uses the request time.
 */
final class Clock implements TimeInterface {

  /**
   * State key for a test instant. Production never sets it.
   */
  public const STATE_KEY = 'lifebalance.clock';

  public function __construct(
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * Unix timestamp the module treats as now.
   */
  public function now(): int {
    $override = $this->state->get(self::STATE_KEY);
    if (is_int($override)) {
      return $override;
    }
    return $this->time->getRequestTime();
  }

  /**
   * Pins now to one instant until clear() is called.
   */
  public function set(int $timestamp): void {
    $this->state->set(self::STATE_KEY, $timestamp);
  }

  /**
   * Returns the module to the request time.
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return $this->now();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) $this->now();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    return $this->now();
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) $this->now();
  }

}
