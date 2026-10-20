<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Payment and refund letters. The body has no scores and no reason.
 */
final class BillingMailHooks {

  public function __construct(
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Builds a payment or refund letter.
   *
   * @param string $key
   *   Mail key, payment_paid or payment_refunded.
   * @param array<string, mixed> $message
   *   The message to fill.
   * @param array<string, mixed> $params
   *   Plan label, price text, and the access end. Nothing else.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $plan = $params['plan'] ?? '';
    $price = $params['price'] ?? '';
    $until = $params['until'] ?? '';
    $plan = is_string($plan) ? $plan : '';
    $price = is_string($price) ? $price : '';
    $until = is_string($until) ? $until : '';
    if ($key === 'payment_paid') {
      $message['subject'] = (string) $this->translation->translate('Оплату зараховано');
      $message['body'][] = (string) $this->translation->translate('Оплату @price грн за «@plan» зараховано.', [
        '@price' => $price,
        '@plan' => $plan,
      ]);
      if ($until !== '') {
        $message['body'][] = (string) $this->translation->translate('Розширене оцінювання відкрите до @until.', [
          '@until' => $until,
        ]);
      }
      $message['body'][] = (string) $this->translation->translate('Збережені відповіді лишаються у вашій історії.');
      return;
    }
    if ($key === 'payment_refunded') {
      $message['subject'] = (string) $this->translation->translate('Оплату повернено');
      $message['body'][] = (string) $this->translation->translate('Оплату @price грн за «@plan» повернено.', [
        '@price' => $price,
        '@plan' => $plan,
      ]);
      $message['body'][] = (string) $this->translation->translate('Доступ із цієї оплати завершено.');
      $message['body'][] = (string) $this->translation->translate('Збережені відповіді лишаються у вашій історії.');
    }
  }

}
