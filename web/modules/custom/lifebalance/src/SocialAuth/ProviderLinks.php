<?php

declare(strict_types=1);

namespace Drupal\lifebalance\SocialAuth;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_auth\Entity\SocialAuth;

/**
 * Lists and detaches the current person's social providers.
 */
final class ProviderLinks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Provider records owned by this account.
   *
   * @return list<\Drupal\social_auth\Entity\SocialAuth>
   *   Records in id order.
   */
  public function records(AccountInterface $account): array {
    if (!$this->entityTypeManager->hasDefinition('social_auth')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('social_auth');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('user_id', $account->id())
      ->sort('id')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $loaded = [];
    foreach ($storage->loadMultiple($ids) as $record) {
      if ($record instanceof SocialAuth) {
        $loaded[] = $record;
      }
    }
    return $loaded;
  }

  /**
   * Whether one provider may be removed and a way in still remains.
   */
  public function canRemoveOne(AccountInterface $account, int $recordCount): bool {
    $methods = $recordCount;
    $mail = $account->getEmail();
    if (is_string($mail) && $mail !== '') {
      $methods++;
    }
    return $methods > 1;
  }

  /**
   * Deletes one of this account's providers when another way in remains.
   */
  public function unlink(int $id, AccountInterface $account): bool {
    if (!$this->entityTypeManager->hasDefinition('social_auth')) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('social_auth');
    $record = $storage->load($id);
    if (!$record instanceof SocialAuth) {
      return FALSE;
    }
    $owner = (int) $record->get('user_id')->target_id;
    if ($owner !== (int) $account->id()) {
      return FALSE;
    }
    if (!$this->canRemoveOne($account, count($this->records($account)))) {
      return FALSE;
    }
    $storage->delete([$record]);
    return TRUE;
  }

}
