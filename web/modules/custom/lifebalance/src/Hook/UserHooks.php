<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Service\EntitlementLog;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Removes personal data when an account is deleted.
 *
 * Criteria stay in the catalog. Their author becomes the anonymous user, and
 * a uid-only change does not start a new revision.
 */
final class UserHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserDataInterface $userData,
    private readonly EntitlementLog $entitlementLog,
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Deletes snapshots, user data, and provider links for this account.
   */
  #[Hook('user_predelete')]
  public function predelete(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return;
    }
    $this->deleteSnapshots($uid);
    $this->deleteEntitlements($uid);
    $this->deleteTrialUsage($uid);
    $this->entitlementLog->anonymizeTarget($uid);
    $this->userData->delete('lifebalance', $uid);
    $this->deleteProviderLinks($uid);
    $this->releaseCriteria($uid);
    $this->loggerFactory->get('lifebalance')->info('Removed personal data for uid @uid.', [
      '@uid' => $uid,
    ]);
  }

  /**
   * Deletes snapshots of this uid, including answer rows.
   */
  private function deleteSnapshots(int $uid): void {
    $storage = $this->entityTypeManager->getStorage('lifebalance_snapshot');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    if ($ids === []) {
      return;
    }
    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Deletes every entitlement of this uid.
   */
  private function deleteEntitlements(int $uid): void {
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    if ($ids === []) {
      return;
    }
    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Deletes the one-time trial marker for this uid.
   */
  private function deleteTrialUsage(int $uid): void {
    if (!$this->database->schema()->tableExists('lifebalance_trial_usage')) {
      return;
    }
    $this->database->delete('lifebalance_trial_usage')
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Deletes Social Auth rows when that entity type is installed.
   */
  private function deleteProviderLinks(int $uid): void {
    if (!$this->entityTypeManager->hasDefinition('social_auth')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('social_auth');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', $uid)
      ->execute();
    if ($ids === []) {
      return;
    }
    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Gives this person's criteria to the anonymous user.
   */
  private function releaseCriteria(int $uid): void {
    $storage = $this->entityTypeManager->getStorage('lifebalance_criterion');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $criterion) {
      if (!$criterion instanceof Criterion || (int) $criterion->getOwnerId() !== $uid) {
        continue;
      }
      $criterion->setOwnerId(0);
      $criterion->save();
    }
  }

}
