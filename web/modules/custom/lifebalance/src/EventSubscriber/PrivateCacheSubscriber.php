<?php

declare(strict_types=1);

namespace Drupal\lifebalance\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks personal LifeBalance responses as private.
 *
 * FinishResponseSubscriber rewrites Cache-Control on authenticated responses.
 * This listener runs later and replaces that header.
 */
final class PrivateCacheSubscriber implements EventSubscriberInterface {

  /**
   * Routes whose responses carry personal scores or answers.
   *
   * @var list<string>
   */
  private const ROUTES = [
    'lifebalance.draft',
    'lifebalance.wheel',
    'lifebalance.questionnaire',
    'lifebalance.account_data',
    'lifebalance.export_json',
    'lifebalance.export_csv',
    'lifebalance.account_delete',
    'lifebalance.trial',
    'lifebalance.history',
    'lifebalance.compare',
    'lifebalance.trend',
    'lifebalance.snapshot',
    'lifebalance.snapshot_delete',
    'lifebalance.admin_users',
    'lifebalance.admin_user',
    'lifebalance.admin_user_wheel',
    'lifebalance.admin_user_snapshot',
    'lifebalance.admin_user_grant',
    'lifebalance.admin_entitlement_extend',
    'lifebalance.admin_entitlement_shorten',
    'lifebalance.admin_entitlement_revoke',
    'lifebalance.admin_stats',
    'lifebalance.admin_entitlement_log',
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse', -10];
    return $events;
  }

  /**
   * Sets Cache-Control on personal routes.
   */
  public function onResponse(ResponseEvent $event): void {
    $name = $event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!is_string($name) || !in_array($name, self::ROUTES, TRUE)) {
      return;
    }
    $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
  }

}
