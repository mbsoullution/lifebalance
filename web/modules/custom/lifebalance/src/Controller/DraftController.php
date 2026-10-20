<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lifebalance\Service\DraftRejected;
use Drupal\lifebalance\Service\DraftStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Saves the current user's draft. The route carries no user id.
 */
final class DraftController {

  private const FLOOD_EVENT = 'lifebalance.draft';

  private const FLOOD_WINDOW = 60;

  private const FLOOD_LIMIT = 120;

  public function __construct(
    private readonly DraftStorage $draftStorage,
    private readonly FloodInterface $flood,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Replaces the draft from a JSON body.
   */
  public function save(Request $request): JsonResponse {
    $account = $this->currentUser->getAccount();
    $identifier = 'uid-' . $account->id();
    $allowed = $this->flood->isAllowed(
      self::FLOOD_EVENT,
      self::FLOOD_LIMIT,
      self::FLOOD_WINDOW,
      $identifier,
    );
    if (!$allowed) {
      return $this->respond(new \stdClass(), 429);
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);

    $raw = $request->getContent();
    if (strlen($raw) > DraftStorage::MAX_BYTES) {
      return $this->respond(new \stdClass(), DraftRejected::INVALID);
    }
    try {
      $payload = json_decode($raw, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->respond(new \stdClass(), DraftRejected::INVALID);
    }
    if (!is_array($payload)) {
      return $this->respond(new \stdClass(), DraftRejected::INVALID);
    }
    try {
      $draft = $this->draftStorage->store($account, $payload);
    }
    catch (DraftRejected $rejected) {
      return $this->respond(new \stdClass(), $rejected->statusCode);
    }
    return $this->respond($draft->toArray(), 200);
  }

  /**
   * JSON response that must not be stored by a shared cache.
   */
  private function respond(mixed $data, int $status): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
