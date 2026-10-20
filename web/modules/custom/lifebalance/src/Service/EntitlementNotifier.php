<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Letters about a grant and about access that is about to end.
 *
 * The expiry letter is sent once, and only when nothing else continues
 * access past that end. The text does not include scores or the reason.
 */
final class EntitlementNotifier {

  /**
   * How long before the end the letter may go out.
   */
  public const NOTICE_WITHIN = 259200;

  /**
   * How long before a trial ends its own letter may go out.
   */
  public const TRIAL_NOTICE_WITHIN = 86400;

  /**
   * User data name set when the person refuses service reminders.
   */
  public const MUTE_NAME = 'mute_service_reminders';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementManager $manager,
    private readonly Connection $database,
    private readonly Clock $clock,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TranslationInterface $translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * Sends the grant letter when the record asks for it.
   */
  public function sendGrant(Entitlement $entitlement): void {
    if (!$entitlement->notifyUser()) {
      return;
    }
    $account = $this->owner($entitlement);
    if ($account === NULL) {
      return;
    }
    $this->send($account, 'entitlement_granted', $this->until($entitlement, $account));
  }

  /**
   * Sends the trial start letter. A muted reminder still allows this one.
   */
  public function sendTrialStarted(Entitlement $entitlement): void {
    if ($entitlement->getSource() !== Entitlement::SOURCE_TRIAL) {
      return;
    }
    $account = $this->owner($entitlement);
    if ($account === NULL) {
      return;
    }
    $this->send($account, 'trial_started', $this->until($entitlement, $account));
  }

  /**
   * Sends the trial letter on the last day, once, unless reminders are muted.
   */
  public function notifyTrialEnding(): void {
    $now = $this->clock->now();
    foreach ($this->trialsDue($now) as $entitlement) {
      $account = $this->owner($entitlement);
      $uid = (int) $entitlement->getOwnerId();
      if ($account === NULL || $this->remindersMuted($uid)) {
        $this->manager->markTrialReminded($entitlement);
        continue;
      }
      if ($this->send($account, 'trial_ending', $this->until($entitlement, $account))) {
        $this->manager->markTrialReminded($entitlement);
      }
    }
  }

  /**
   * Sends one expiry letter per person, then marks those records.
   */
  public function notifyExpiring(): void {
    $now = $this->clock->now();
    $candidates = $this->due($now);
    if ($candidates === []) {
      return;
    }
    $siblings = $this->activeFor(array_map(
      static fn (Entitlement $entitlement): int => (int) $entitlement->getOwnerId(),
      $candidates,
    ));
    /** @var array<int, list<\Drupal\lifebalance\Entity\Entitlement>> $by_user */
    $by_user = [];
    foreach ($candidates as $entitlement) {
      if ($this->continued($entitlement, $siblings)) {
        continue;
      }
      $by_user[$entitlement->getOwnerId()][] = $entitlement;
    }
    foreach ($by_user as $records) {
      usort($records, static function (Entitlement $left, Entitlement $right): int {
        return ($left->getEnds() ?? 0) <=> ($right->getEnds() ?? 0);
      });
      $first = $records[0];
      $account = $this->owner($first);
      if ($account === NULL || $this->remindersMuted((int) $account->id())) {
        continue;
      }
      if (!$this->send($account, 'entitlement_expiring', $this->until($first, $account))) {
        continue;
      }
      foreach ($records as $record) {
        $this->manager->markExpiryNotified($record);
      }
    }
  }

  /**
   * Active trials that end within one day and have no reminder row yet.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Records still inside the last day.
   */
  private function trialsDue(int $now): array {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->fields('e', ['id']);
    $query->condition('e.source', Entitlement::SOURCE_TRIAL);
    $query->condition('e.status', Entitlement::STATUS_ACTIVE);
    $query->isNotNull('e.ends');
    $query->condition('e.ends', $now, '>');
    $query->condition('e.ends', $now + self::TRIAL_NOTICE_WITHIN, '<=');
    $reminded = $this->database->select('lifebalance_entitlement_log', 'l');
    $reminded->addField('l', 'id');
    $reminded->where('[l].[entitlement_id] = [e].[id]');
    $reminded->condition('l.action', EntitlementLog::TRIAL_REMIND);
    $query->notExists($reminded);
    $statement = $query->execute();
    if ($statement === NULL) {
      return [];
    }
    $ids = [];
    foreach ($statement as $record) {
      $id = $record->id ?? NULL;
      if (is_numeric($id)) {
        $ids[] = (int) $id;
      }
    }
    if ($ids === []) {
      return [];
    }
    $loaded = [];
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if ($entitlement instanceof Entitlement && $entitlement->getEnds() !== NULL) {
        $loaded[] = $entitlement;
      }
    }
    return $loaded;
  }

  /**
   * Active records whose end falls inside the notice window.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Records that have not been marked yet.
   */
  private function due(int $now): array {
    $query = $this->database->select('lifebalance_entitlement', 'e');
    $query->fields('e', ['id']);
    $query->condition('e.feature_set', Entitlement::FEATURE_PREMIUM);
    $query->condition('e.status', Entitlement::STATUS_ACTIVE);
    $query->condition('e.expiry_notified', 0);
    $query->isNotNull('e.ends');
    $query->condition('e.ends', $now, '>');
    $query->condition('e.ends', $now + self::NOTICE_WITHIN, '<=');
    $statement = $query->execute();
    if ($statement === NULL) {
      return [];
    }
    $ids = [];
    foreach ($statement as $record) {
      $id = $record->id ?? NULL;
      if (is_numeric($id)) {
        $ids[] = (int) $id;
      }
    }
    if ($ids === []) {
      return [];
    }
    $loaded = [];
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if ($entitlement instanceof Entitlement && $entitlement->getEnds() !== NULL) {
        $loaded[] = $entitlement;
      }
    }
    return $loaded;
  }

  /**
   * Active Premium records for these accounts.
   *
   * @param list<int> $uids
   *   Account ids.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Records that are not revoked.
   */
  private function activeFor(array $uids): array {
    $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
    if ($uids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uids, 'IN')
      ->condition('feature_set', Entitlement::FEATURE_PREMIUM)
      ->condition('status', Entitlement::STATUS_ACTIVE)
      ->execute();
    if ($ids === []) {
      return [];
    }
    $loaded = [];
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if ($entitlement instanceof Entitlement) {
        $loaded[] = $entitlement;
      }
    }
    return $loaded;
  }

  /**
   * Whether another active record still covers the person after this end.
   *
   * @param \Drupal\lifebalance\Entity\Entitlement $record
   *   The record that is about to end.
   * @param list<\Drupal\lifebalance\Entity\Entitlement> $siblings
   *   Active records for the same accounts.
   */
  private function continued(Entitlement $record, array $siblings): bool {
    $ends = $record->getEnds();
    if ($ends === NULL) {
      return TRUE;
    }
    foreach ($siblings as $other) {
      if ((int) $other->id() === (int) $record->id()) {
        continue;
      }
      if ($other->getOwnerId() !== $record->getOwnerId()) {
        continue;
      }
      if ($other->getStarts() > $ends) {
        continue;
      }
      $other_ends = $other->getEnds();
      if ($other_ends === NULL || $other_ends > $ends) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether this person asked not to receive service reminders.
   */
  private function remindersMuted(int $uid): bool {
    if ($uid < 1) {
      return FALSE;
    }
    $value = $this->userData->get('lifebalance', $uid, self::MUTE_NAME);
    return $value === 1 || $value === '1' || $value === TRUE;
  }

  /**
   * The account that should receive the letter, when it has an address.
   */
  private function owner(Entitlement $entitlement): ?UserInterface {
    $account = $entitlement->getOwner();
    if ((int) $account->id() < 1) {
      return NULL;
    }
    $mail = $account->getEmail();
    if ($mail === NULL || $mail === '') {
      return NULL;
    }
    return $account;
  }

  /**
   * Date text for the letter. Unlimited grants have no date.
   */
  private function until(Entitlement $entitlement, UserInterface $account): string {
    $ends = $entitlement->getEnds();
    if ($ends === NULL) {
      return (string) $this->translation->translate('без обмеження дати');
    }
    $zone = $account->getTimeZone();
    return $this->dateFormatter->format(
      $ends,
      'custom',
      'd.m.Y H:i',
      $zone !== '' ? $zone : NULL,
    );
  }

  /**
   * Sends one letter. FALSE when the mail system refuses it.
   */
  private function send(UserInterface $account, string $key, string $until): bool {
    $mail = $account->getEmail();
    if ($mail === NULL || $mail === '') {
      return FALSE;
    }
    $result = $this->mailManager->mail(
      'lifebalance',
      $key,
      $mail,
      $account->getPreferredLangcode(),
      ['until' => $until],
    );
    $sent = !empty($result['result']);
    if ($sent) {
      $this->loggerFactory->get('lifebalance')->info('Sent entitlement mail @key for uid @uid.', [
        '@key' => $key,
        '@uid' => $account->id(),
      ]);
    }
    return $sent;
  }

}
