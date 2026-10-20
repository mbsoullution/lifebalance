<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * Uses monobank when a token is configured, and the test gateway otherwise.
 */
final class PaymentGatewayFactory {

  /**
   * Active gateway for this environment.
   */
  public static function select(FakeGateway $fake, MonobankGateway $monobank): PaymentGatewayInterface {
    return $monobank->hasToken() ? $monobank : $fake;
  }

}
