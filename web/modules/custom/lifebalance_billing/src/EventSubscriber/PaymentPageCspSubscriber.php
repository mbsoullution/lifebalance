<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the payment-page host to the two CSP directives Seckit cannot store.
 *
 * Seckit 2.0.3 emits default-src, script-src and the other fixed directives.
 * It has no form-action or navigate-to field. Checkout redirects the browser
 * to the hosted page https://pay.mbnk.biz, so that host is allowed only there.
 * script-src stays limited to this site.
 */
final class PaymentPageCspSubscriber implements EventSubscriberInterface {

  private const HOSTS = "'self' https://accounts.google.com https://www.facebook.com https://pay.mbnk.biz";

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::RESPONSE][] = ['onResponse', -5];
    return $events;
  }

  /**
   * Appends form-action and navigate-to when a policy is already present.
   */
  public function onResponse(ResponseEvent $event): void {
    $headers = $event->getResponse()->headers;
    $policy = $headers->get('Content-Security-Policy');
    if (!is_string($policy) || $policy === '') {
      return;
    }
    if (!str_contains($policy, 'form-action ')) {
      $policy .= '; form-action ' . self::HOSTS;
    }
    if (!str_contains($policy, 'navigate-to ')) {
      $policy .= '; navigate-to ' . self::HOSTS;
    }
    $headers->set('Content-Security-Policy', $policy);
  }

}
