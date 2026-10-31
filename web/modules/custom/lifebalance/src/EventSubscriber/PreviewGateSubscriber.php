<?php

declare(strict_types=1);

namespace Drupal\lifebalance\EventSubscriber;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Seo\PreviewLock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Asks for a browser password on the preview site and marks it noindex.
 */
final class PreviewGateSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PreviewLock $previewLock,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onRequest', 100];
    $events[KernelEvents::RESPONSE][] = ['onResponse', 0];
    return $events;
  }

  /**
   * Challenges anonymous preview requests when the server password file exists.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!PreviewLock::isPreviewHost($request->getHost())) {
      return;
    }
    $credentials = $this->previewLock->credentials();
    if ($credentials === NULL) {
      return;
    }
    if (PreviewLock::allows(
      $this->serverString($request, 'REMOTE_USER'),
      $this->serverString($request, 'PHP_AUTH_USER'),
      $this->serverString($request, 'PHP_AUTH_PW'),
      $this->authorization($request),
      $credentials['user'],
      $credentials['hash'],
    )) {
      return;
    }
    $event->setResponse($this->challenge());
  }

  /**
   * Sends a noindex header for every preview response, including the challenge.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!PreviewLock::isPreviewHost($event->getRequest()->getHost())) {
      return;
    }
    $event->getResponse()->headers->set(
      'X-Robots-Tag',
      'noindex, nofollow, noarchive',
    );
  }

  /**
   * One server value, when it is a string.
   */
  private function serverString(Request $request, string $key): ?string {
    $value = $request->server->get($key);
    return is_string($value) ? $value : NULL;
  }

  /**
   * Authorization header from PHP-FPM or the rewrite environment.
   */
  private function authorization(Request $request): ?string {
    $header = $request->headers->get('Authorization');
    if (is_string($header) && $header !== '') {
      return $header;
    }
    $forwarded = $this->serverString($request, 'HTTP_AUTHORIZATION')
      ?? $this->serverString($request, 'REDIRECT_HTTP_AUTHORIZATION');
    return $forwarded;
  }

  /**
   * Plain challenge. The page itself is not rendered.
   */
  private function challenge(): Response {
    $response = new Response(
      (string) $this->translation->translate('Введіть логін і пароль.'),
      401,
    );
    $response->headers->set('WWW-Authenticate', 'Basic realm="LifeBalance"');
    $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
    return $response;
  }

}
