<?php

declare(strict_types=1);

namespace Drupal\lifebalance\EventSubscriber;

use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends an anonymous visitor from a personal page to the login page.
 */
final class AnonymousLoginRedirectSubscriber implements EventSubscriberInterface {

  /**
   * HTML routes that require a person rather than a 403 page.
   *
   * @var list<string>
   */
  private const ROUTES = [
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
  ];

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RedirectDestinationInterface $destination,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Setting a response stops this event. This must run before
    // DefaultExceptionHtmlSubscriber (-128) builds the 403 page.
    $events[KernelEvents::EXCEPTION][] = ['onException', 0];
    return $events;
  }

  /**
   * Redirects an anonymous HTML request for the wheel.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }
    if ($this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->getRequestFormat() !== 'html') {
      return;
    }
    $name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!is_string($name) || !in_array($name, self::ROUTES, TRUE)) {
      return;
    }
    $url = Url::fromRoute('user.login', [], [
      'query' => $this->destination->getAsArray(),
      'absolute' => TRUE,
    ]);
    $event->setResponse(new RedirectResponse($url->toString()));
  }

}
