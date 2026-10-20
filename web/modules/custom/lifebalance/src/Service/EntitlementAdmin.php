<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Reads entitlement records for the admin card.
 *
 * The query does not select scores, answers, notes, or goal text.
 */
final class EntitlementAdmin {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementLog $entitlementLog,
    private readonly Clock $clock,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Whether this account already has a trial record.
   */
  public function hadTrial(int $uid): bool {
    if ($uid < 1) {
      return FALSE;
    }
    $ids = $this->entityTypeManager->getStorage('lifebalance_entitlement')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('source', Entitlement::SOURCE_TRIAL)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Records for one account, latest start first, with revision history.
   *
   * @return list<array{
   *   id: int,
   *   source: string,
   *   starts: int,
   *   ends: int|null,
   *   status: string,
   *   reason: string,
   *   manual: bool,
   *   open: bool,
   *   revisions: list<array<string, int|string>>
   *   }>
   *   Stored access. Reasons stay in the revision list.
   */
  public function records(int $uid): array {
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('feature_set', Entitlement::FEATURE_PREMIUM)
      ->sort('starts', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $now = $this->clock->now();
    $records = [];
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if (!$entitlement instanceof Entitlement) {
        continue;
      }
      $revisions = $this->revisions($entitlement);
      $records[] = [
        'id' => (int) $entitlement->id(),
        'source' => $entitlement->getSource(),
        'starts' => $entitlement->getStarts(),
        'ends' => $entitlement->getEnds(),
        'status' => $entitlement->getStatus(),
        'reason' => $entitlement->getReason(),
        'manual' => $entitlement->isManual(),
        'open' => $this->isOpen($entitlement, $now),
        'revisions' => $revisions,
      ];
    }
    return $records;
  }

  /**
   * Revisions of one record, oldest first.
   *
   * @return list<array{created: int, actor: string, action: string, reason: string}>
   *   Who changed the record, and why.
   */
  private function revisions(Entitlement $entitlement): array {
    $logged = $this->entitlementLog->forEntitlement((int) $entitlement->id());
    $names = $this->actorNames($logged);
    $rows = [];
    foreach ($logged as $row) {
      $rows[] = [
        'created' => $row['created'],
        'actor' => $this->actorName($row['actor_uid'], $names),
        'action' => $this->actionLabel($row['action']),
        'reason' => $row['reason'],
      ];
    }
    return $rows;
  }

  /**
   * Display names for revision authors.
   *
   * @param list<array{actor_uid: int}> $rows
   *   Log rows whose authors are needed.
   *
   * @return array<int, string>
   *   Names keyed by uid.
   */
  private function actorNames(array $rows): array {
    $uids = [];
    foreach ($rows as $row) {
      $uid = $row['actor_uid'];
      if ($uid > 0) {
        $uids[$uid] = $uid;
      }
    }
    if ($uids === []) {
      return [];
    }
    $names = [];
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
    foreach ($users as $user) {
      $names[(int) $user->id()] = $user->getDisplayName();
    }
    return $names;
  }

  /**
   * Visible name of a revision author.
   *
   * @param int $uid
   *   Revision author id. Zero means the system.
   * @param array<int, string> $names
   *   Names keyed by uid.
   */
  private function actorName(int $uid, array $names): string {
    if ($uid < 1) {
      return (string) $this->translation->translate('Система');
    }
    if (isset($names[$uid])) {
      return $names[$uid];
    }
    return (string) $this->translation->translate('Обліковий запис видалено');
  }

  /**
   * Ukrainian label for a stored action.
   */
  public function actionLabel(string $action): string {
    $label = match ($action) {
      EntitlementLog::REVOKE, EntitlementLog::REFUND_REVOKE => $this->translation->translate('Скасовано'),
      EntitlementLog::EXTEND => $this->translation->translate('Продовжено'),
      EntitlementLog::SHORTEN => $this->translation->translate('Скорочено'),
      EntitlementLog::EXPIRE_NOTICE, EntitlementLog::TRIAL_REMIND => $this->translation->translate('Нагадування надіслано'),
      default => $this->translation->translate('Надано'),
    };
    return (string) $label;
  }

  /**
   * Whether the record grants access at this instant.
   */
  private function isOpen(Entitlement $entitlement, int $now): bool {
    if ($entitlement->getStatus() !== Entitlement::STATUS_ACTIVE) {
      return FALSE;
    }
    if ($entitlement->getStarts() > $now) {
      return FALSE;
    }
    $ends = $entitlement->getEnds();
    return $ends === NULL || $ends > $now;
  }

}
