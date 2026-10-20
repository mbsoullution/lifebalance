<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks billing pages as private. They show one person's order.
 */
final class PrivateCacheSubscriber implements EventSubscriberInterface {

  /**
   * Routes that show one person's payment status.
   *
   * @var list<string>
   */
  private const ROUTES = [
    'lifebalance_billing.checkout',
    'lifebalance_billing.return',
    'lifebalance_billing.return_status',
    'lifebalance_billing.webhook',
    'lifebalance_billing.orders',
    'lifebalance_billing.order_refund',
    'lifebalance_billing.order_chargeback',
    'lifebalance_billing.order_partial',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse', -10];
    return $events;
  }

  /**
   * Sets Cache-Control on checkout, return, and webhook responses.
   */
  public function onResponse(ResponseEvent $event): void {
    $name = $event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!is_string($name) || !in_array($name, self::ROUTES, TRUE)) {
      return;
    }
    $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
  }

}
