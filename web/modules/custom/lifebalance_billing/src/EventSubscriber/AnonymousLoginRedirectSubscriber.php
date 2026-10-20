<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\EventSubscriber;

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
 * Sends an anonymous visitor from checkout and the return page to login.
 *
 * The webhook stays anonymous. Its signature is the check.
 */
final class AnonymousLoginRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RedirectDestinationInterface $destination,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::EXCEPTION][] = ['onException', 0];
    return $events;
  }

  /**
   * Redirects anonymous access-denied responses for billing pages.
   */
  public function onException(ExceptionEvent $event): void {
    if ($this->currentUser->isAuthenticated()) {
      return;
    }
    $exception = $event->getThrowable();
    if (!$exception instanceof AccessDeniedHttpException) {
      return;
    }
    $request = $event->getRequest();
    if ($request->getRequestFormat() !== 'html') {
      return;
    }
    $name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!is_string($name) || !in_array($name, [
      'lifebalance_billing.checkout',
      'lifebalance_billing.return',
      'lifebalance_billing.return_status',
    ], TRUE)) {
      return;
    }
    $url = Url::fromRoute('user.login', [], [
      'query' => $this->destination->getAsArray(),
      'absolute' => TRUE,
    ]);
    $event->setResponse(new RedirectResponse($url->toString()));
  }

}
