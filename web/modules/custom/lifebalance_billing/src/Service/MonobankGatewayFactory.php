<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Builds the monobank adapter. The token comes from settings, not config.
 */
final class MonobankGatewayFactory {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
  ) {}

  /**
   * Adapter using the environment token, which may be empty.
   */
  public function create(): MonobankGateway {
    $token = Settings::get('lifebalance_billing.monobank_token', '');
    return new MonobankGateway($this->httpClient, $this->state, is_string($token) ? $token : '');
  }

}
