<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\user\UserInterface;

/**
 * Letters after a payment is stored. The body has no scores and no reason.
 */
final class BillingNotifier {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TranslationInterface $translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Sends the letter for a paid or fully refunded order, when there is one.
   */
  public function afterStatus(PaymentOrder $order): void {
    $status = $order->getStatus();
    if ($status === PaymentOrder::STATUS_PAID) {
      $this->send($order, 'payment_paid');
      return;
    }
    if ($status === PaymentOrder::STATUS_REFUNDED) {
      $this->send($order, 'payment_refunded');
    }
  }

  /**
   * Sends one letter. A missing address is skipped.
   */
  private function send(PaymentOrder $order, string $key): void {
    $account = $this->owner($order);
    if (!$account instanceof UserInterface) {
      return;
    }
    $mail = $account->getEmail();
    if ($mail === NULL || $mail === '') {
      return;
    }
    $result = $this->mailManager->mail(
      'lifebalance_billing',
      $key,
      $mail,
      $account->getPreferredLangcode(),
      [
        'plan' => (string) $order->get('plan_label')->value,
        'until' => $this->until($order, $account),
        'price' => MinorMoney::format($order->getAmountMinor()),
      ],
    );
    if (!empty($result['result'])) {
      $this->loggerFactory->get('lifebalance_billing')->info('Sent billing mail @key for uid @uid.', [
        '@key' => $key,
        '@uid' => $account->id(),
      ]);
    }
  }

  /**
   * The buyer, when the account and its address still exist.
   */
  private function owner(PaymentOrder $order): ?UserInterface {
    $uid = $order->getOwnerId();
    if ($uid < 1) {
      return NULL;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account instanceof UserInterface) {
      return NULL;
    }
    return $account;
  }

  /**
   * End of the access this order created, or an empty string.
   */
  private function until(PaymentOrder $order, UserInterface $account): string {
    $id = $order->getEntitlementId();
    if ($id < 1) {
      return '';
    }
    $entitlement = $this->entityTypeManager->getStorage('lifebalance_entitlement')->load($id);
    if (!$entitlement instanceof Entitlement) {
      return '';
    }
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

}
