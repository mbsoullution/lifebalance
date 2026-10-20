<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Database\Connection;
use Drupal\lifebalance\Service\Clock;

/**
 * Append-only billing events. There is no update and no delete.
 */
final class EventLog {

  public function __construct(
    private readonly Connection $database,
    private readonly Clock $clock,
  ) {}

  /**
   * Records the outcome of one notice. The raw body is not written.
   */
  public function append(int $orderId, string $type, string $providerStatus, string $payloadHash, string $result): void {
    if ($orderId < 1 || !in_array($type, ['webhook', 'status_check', 'admin_action'], TRUE)) {
      throw new \InvalidArgumentException('This billing event cannot be stored.');
    }
    $this->database->insert('lifebalance_billing_event')
      ->fields([
        'order_id' => $orderId,
        'type' => $type,
        'provider_status' => mb_substr($providerStatus, 0, 64),
        'payload_hash' => $payloadHash,
        'received_at' => $this->clock->now(),
        'result' => mb_substr($result, 0, 32),
      ])
      ->execute();
  }

}
