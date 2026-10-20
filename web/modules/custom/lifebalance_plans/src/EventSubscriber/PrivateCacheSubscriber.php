<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks plan pages as private. They show one person's goals.
 */
final class PrivateCacheSubscriber implements EventSubscriberInterface {

  /**
   * Routes that show one person's goals or prompts.
   *
   * @var list<string>
   */
  private const ROUTES = [
    'lifebalance_plans.focus',
    'lifebalance_plans.plan',
    'lifebalance_plans.plan_30',
    'lifebalance_plans.plan_90',
    'lifebalance_plans.plan_closed',
    'lifebalance_plans.plan_add',
    'lifebalance_plans.goal',
    'lifebalance_plans.print',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse', -10];
    return $events;
  }

  /**
   * Sets Cache-Control on plan and focus pages.
   */
  public function onResponse(ResponseEvent $event): void {
    $name = $event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!is_string($name) || !in_array($name, self::ROUTES, TRUE)) {
      return;
    }
    $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
  }

}
