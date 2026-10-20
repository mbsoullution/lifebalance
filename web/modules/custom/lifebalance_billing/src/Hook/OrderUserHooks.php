<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\user\UserInterface;

/**
 * Keeps orders when an account is deleted. The owner column becomes empty.
 */
final class OrderUserHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Detaches orders from the account that is about to disappear.
   */
  #[Hook('user_predelete')]
  public function predelete(UserInterface $account): void {
    $uid = (int) $account->id();
    if ($uid < 1) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $order) {
      if (!$order instanceof PaymentOrder) {
        continue;
      }
      $order->set('uid', NULL);
      $order->save();
    }
    $this->loggerFactory->get('lifebalance_billing')->info('Detached billing orders for uid @uid.', [
      '@uid' => $uid,
    ]);
  }

}
