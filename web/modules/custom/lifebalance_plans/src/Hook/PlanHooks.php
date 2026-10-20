<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Hook;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Service\PlanManager;

/**
 * Owner checks, completion, the invitation, and the completion letter.
 */
final class PlanHooks {

  public function __construct(
    private readonly PlanManager $plans,
    private readonly AccountProxyInterface $currentUser,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Closes plans whose last day has ended and sends the letter.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->plans->completeDue();
  }

  /**
   * A new snapshot answers the invitation to assess again.
   */
  #[Hook('lifebalance_snapshot_insert')]
  public function snapshotInsert(Snapshot $snapshot): void {
    $this->plans->clearInvite((int) $snapshot->getOwnerId());
  }

  /**
   * Only the owner saves a plan, except the cron completion.
   */
  #[Hook('lifebalance_plan_presave')]
  public function presave(Plan $plan): void {
    if (!$plan->appliedBySystem) {
      $uid = (int) $plan->getOwnerId();
      if ($uid < 1 || $uid !== (int) $this->currentUser->id()) {
        throw new EntityStorageException('A plan can be saved only by its owner.');
      }
    }
    $this->plans->assertSingleActive($plan);
  }

  /**
   * Invalidates the owner's plan list.
   */
  #[Hook('lifebalance_plan_insert')]
  #[Hook('lifebalance_plan_update')]
  #[Hook('lifebalance_plan_delete')]
  public function invalidateList(Plan $plan): void {
    $this->cacheTagsInvalidator->invalidateTags([
      'lifebalance_plan_list:' . $plan->getOwnerId(),
    ]);
  }

  /**
   * Completion letter. The body has no scores and no goal text.
   *
   * @param string $key
   *   Mail key. Only plan_completed is built here.
   * @param array<string, mixed> $message
   *   The message to fill.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message): void {
    if ($key !== 'plan_completed') {
      return;
    }
    $message['subject'] = (string) $this->translation->translate('План на 30 днів завершився');
    $message['body'][] = (string) $this->translation->translate('План на 30 днів завершився.');
    $message['body'][] = (string) $this->translation->translate('Можна пройти нову самооцінку.');
  }

}
