<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance_billing\Service\PaymentGatewayInterface;
use Drupal\lifebalance_billing\Service\PaymentProcessor;
use Drupal\lifebalance_billing\Service\ProviderEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Receives a signed provider notice. A bad signature is rejected.
 *
 * The raw body is hashed and then discarded. This route has no form token:
 * the provider cannot send one. The signature is the check.
 */
final class WebhookController extends ControllerBase {

  private const MAX_BODY = 16384;

  public function __construct(
    protected PaymentGatewayInterface $gateway,
    protected PaymentProcessor $processor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PaymentGatewayInterface::class),
      $container->get(PaymentProcessor::class),
    );
  }

  /**
   * Verifies the signature and applies the notice.
   */
  public function receive(Request $request, string $provider): Response {
    if ($provider !== $this->gateway->id()) {
      throw new NotFoundHttpException();
    }
    $raw = $request->getContent();
    if (strlen($raw) > self::MAX_BODY) {
      return new Response('', 400);
    }
    $signature = $request->headers->get($this->gateway->signatureHeader(), '');
    if (!is_string($signature) || $signature === '' || !$this->gateway->verifyWebhook($raw, $signature)) {
      $this->getLogger('lifebalance_billing')->warning('Rejected a billing webhook with an invalid signature.');
      return new Response('', 401);
    }
    $event = $this->gateway->noticeFromWebhook($raw);
    if (!$event instanceof ProviderEvent) {
      $this->getLogger('lifebalance_billing')->warning('Ignored a billing notice that could not be read.');
      return new Response('', 400);
    }
    $this->processor->apply($event, 'webhook');
    return new Response('', 200);
  }

}
