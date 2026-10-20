<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Entity\Entitlement;

/**
 * Premium is the union of active entitlement records at one instant.
 */
final class EntityEntitlementChecker implements EntitlementCheckerInterface {

  /**
   * Features of the premium set when config has not been saved yet.
   */
  private const PREMIUM_FEATURES = [
    'premium_criteria',
    'recommendations',
    'goals',
    'plan_30',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Clock $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hasFeatureAccess(AccountInterface $account, string $feature, ?int $at = NULL): bool {
    if ($feature === '') {
      return FALSE;
    }
    foreach ($this->getActive($account, $at) as $entitlement) {
      if (in_array($feature, $this->features($entitlement->getFeatureSet()), TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getEffectiveStatus(AccountInterface $account, ?int $at = NULL): EffectiveStatus {
    $active = $this->getActive($account, $at);
    if ($active !== []) {
      return $this->activeStatus($active);
    }
    $records = $this->loadRecords($account, FALSE);
    if ($records === []) {
      return new EffectiveStatus(EffectiveStatus::FREE, [], NULL);
    }
    $latest = NULL;
    foreach ($records as $entitlement) {
      $ends = $entitlement->getEnds();
      if ($ends !== NULL && ($latest === NULL || $ends > $latest)) {
        $latest = $ends;
      }
    }
    return new EffectiveStatus(EffectiveStatus::EXPIRED, [], $latest);
  }

  /**
   * {@inheritdoc}
   */
  public function hasPremium(AccountInterface $account, ?int $at = NULL): bool {
    foreach (self::PREMIUM_FEATURES as $feature) {
      if ($this->hasFeatureAccess($account, $feature, $at)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getActive(AccountInterface $account, ?int $at = NULL): array {
    $at = $at ?? $this->clock->now();
    $active = [];
    foreach ($this->loadOpen($account) as $entitlement) {
      if ($entitlement->getStarts() > $at) {
        continue;
      }
      $ends = $entitlement->getEnds();
      if ($ends !== NULL && $ends <= $at) {
        continue;
      }
      $active[] = $entitlement;
    }
    return $active;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheability(AccountInterface $account, ?int $at = NULL): CacheableMetadata {
    $at = $at ?? $this->clock->now();
    $uid = (int) $account->id();
    $metadata = new CacheableMetadata();
    $metadata->setCacheContexts(['user']);
    $metadata->setCacheTags(['lifebalance_entitlement:' . $uid]);
    $boundaries = [];
    foreach ($this->loadOpen($account) as $entitlement) {
      if ($entitlement->getStarts() > $at) {
        $boundaries[] = $entitlement->getStarts();
      }
      $ends = $entitlement->getEnds();
      if ($ends !== NULL && $ends > $at) {
        $boundaries[] = $ends;
      }
    }
    if ($boundaries === []) {
      $metadata->setCacheMaxAge(Cache::PERMANENT);
      return $metadata;
    }
    $metadata->setCacheMaxAge(min($boundaries) - $at);
    return $metadata;
  }

  /**
   * Non-revoked Premium records for this account.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Records whose status is still active.
   */
  private function loadOpen(AccountInterface $account): array {
    return $this->loadRecords($account, TRUE);
  }

  /**
   * Premium records for this account.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Stored records. Active-only when $open is TRUE.
   */
  private function loadRecords(AccountInterface $account, bool $open): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_entitlement');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('feature_set', Entitlement::FEATURE_PREMIUM);
    if ($open) {
      $query->condition('status', Entitlement::STATUS_ACTIVE);
    }
    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }
    $records = [];
    foreach ($storage->loadMultiple($ids) as $entitlement) {
      if ($entitlement instanceof Entitlement) {
        $records[] = $entitlement;
      }
    }
    return $records;
  }

  /**
   * Summary of the records that currently grant access.
   *
   * @param list<\Drupal\lifebalance\Entity\Entitlement> $active
   *   Records active at the instant.
   */
  private function activeStatus(array $active): EffectiveStatus {
    $sources = [];
    $ends = NULL;
    $open = FALSE;
    $only_trial = TRUE;
    foreach ($active as $entitlement) {
      $source = $entitlement->getSource();
      $sources[$source] = $source;
      if ($source !== Entitlement::SOURCE_TRIAL) {
        $only_trial = FALSE;
      }
      $record_ends = $entitlement->getEnds();
      if ($record_ends === NULL) {
        $open = TRUE;
        continue;
      }
      if (!$open && ($ends === NULL || $record_ends > $ends)) {
        $ends = $record_ends;
      }
    }
    $names = array_values($sources);
    sort($names);
    $status = $only_trial ? EffectiveStatus::TRIAL : EffectiveStatus::PREMIUM;
    return new EffectiveStatus($status, $names, $open ? NULL : $ends);
  }

  /**
   * Features configured for one set.
   *
   * @return list<string>
   *   Feature ids.
   */
  private function features(string $featureSet): array {
    $configured = $this->configFactory->get('lifebalance.settings')->get('feature_sets');
    $raw = is_array($configured) ? ($configured[$featureSet] ?? NULL) : NULL;
    if (!is_array($raw)) {
      return $featureSet === Entitlement::FEATURE_PREMIUM ? self::PREMIUM_FEATURES : [];
    }
    $features = [];
    foreach ($raw as $feature) {
      if (is_string($feature) && $feature !== '') {
        $features[] = $feature;
      }
    }
    return $features;
  }

}
