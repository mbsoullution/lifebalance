<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_plans\Repository\GoalRepository;

/**
 * A quiet invitation to score a sector again. No mail is sent.
 *
 * The clock starts at the earliest goal in that sector. Seven days shows the
 * shorter invitation. Thirty days replaces it.
 */
final class ReassessmentPrompt {

  private const WEEK = 7 * 86400;

  private const MONTH = 30 * 86400;

  public function __construct(
    private readonly GoalRepository $goals,
    private readonly SectorRepository $sectors,
    private readonly Clock $clock,
  ) {}

  /**
   * Sectors whose first goal is at least seven days old.
   *
   * @return list<array{sector_id: string, label: string, days: int}>
   *   One row per sector, in catalog order.
   */
  public function forAccount(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return [];
    }
    $earliest = [];
    foreach ($this->goals->loadForOwner($uid) as $goal) {
      $sector = $goal->getSectorId();
      $created = $goal->getCreatedTime();
      if (!isset($earliest[$sector]) || $created < $earliest[$sector]) {
        $earliest[$sector] = $created;
      }
    }
    $now = $this->clock->now();
    $labels = [];
    foreach ($this->sectors->loadActive() as $id => $sector) {
      $labels[$id] = (string) $sector->label();
    }
    $prompts = [];
    foreach ($labels as $id => $label) {
      if (!isset($earliest[$id])) {
        continue;
      }
      $age = $now - $earliest[$id];
      if ($age >= self::MONTH) {
        $days = 30;
      }
      elseif ($age >= self::WEEK) {
        $days = 7;
      }
      else {
        continue;
      }
      $prompts[] = [
        'sector_id' => $id,
        'label' => $label,
        'days' => $days,
      ];
    }
    return $prompts;
  }

}
