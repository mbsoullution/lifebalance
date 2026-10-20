<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Hook;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_plans\Entity\Recommendation;

/**
 * Stamps recommendation revisions and refuses an invalid library row.
 */
final class RecommendationHooks {

  public function __construct(
    private readonly Clock $clock,
    #[Autowire(service: 'current_user')]
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Sets the revision time and stores only a valid recommendation.
   */
  #[Hook('lifebalance_recommendation_presave')]
  public function presave(Recommendation $recommendation): void {
    if ($recommendation->getOwnerId() === NULL || (int) $recommendation->getOwnerId() === 0) {
      $recommendation->setOwnerId((int) $this->currentUser->id());
    }
    if ($recommendation->isNewRevision()) {
      $recommendation->setRevisionCreationTime($this->clock->now());
      $recommendation->setRevisionUserId((int) $this->currentUser->id());
    }
    $violations = $recommendation->validate();
    if ($violations->count() > 0) {
      throw new EntityStorageException((string) $violations->get(0)->getMessage());
    }
  }

}
