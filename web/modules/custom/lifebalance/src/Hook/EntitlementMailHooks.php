<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Service\EntitlementNotifier;

/**
 * Grant and expiry letters. The body has no scores and no reason.
 */
final class EntitlementMailHooks {

  public function __construct(
    private readonly EntitlementNotifier $notifier,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Sends expiry letters that are due.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->notifier->notifyExpiring();
    $this->notifier->notifyTrialEnding();
  }

  /**
   * Builds a grant or expiry letter.
   *
   * @param string $key
   *   Mail key. Trial letters carry the end date and no personal text.
   * @param array<string, mixed> $message
   *   The message to fill.
   * @param array<string, mixed> $params
   *   The until text, and nothing personal beyond it.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $until = $params['until'] ?? '';
    $until = is_string($until) ? $until : '';
    if ($key === 'entitlement_granted') {
      $message['subject'] = (string) $this->translation->translate('Розширене оцінювання відкрито');
      $message['body'][] = (string) $this->translation->translate('Вам відкрито розширене оцінювання (@until).', [
        '@until' => $until,
      ]);
      $message['body'][] = (string) $this->translation->translate('Збережені відповіді лишаються у вашій історії.');
      return;
    }
    if ($key === 'entitlement_expiring') {
      $message['subject'] = (string) $this->translation->translate('Розширене оцінювання завершується');
      $message['body'][] = (string) $this->translation->translate('Розширене оцінювання буде доступне до @until.', [
        '@until' => $until,
      ]);
      $message['body'][] = (string) $this->translation->translate('Після цієї дати нові розширені відповіді будуть недоступні.');
      $message['body'][] = (string) $this->translation->translate('Збережені відповіді лишаються у вашій історії.');
      return;
    }
    if ($key === 'trial_started') {
      $message['subject'] = (string) $this->translation->translate('Ознайомлення на 7 днів розпочато');
      $message['body'][] = (string) $this->translation->translate('Картка не потрібна. Списань немає.');
      $message['body'][] = (string) $this->translation->translate('Ознайомлення діє до @until.', [
        '@until' => $until,
      ]);
      $message['body'][] = (string) $this->translation->translate('Після цієї дати збережені зрізи й відповіді лишаються.');
      return;
    }
    if ($key === 'trial_ending') {
      $message['subject'] = (string) $this->translation->translate('Ознайомлення завершується');
      $message['body'][] = (string) $this->translation->translate('Ознайомлення завершується @until.', [
        '@until' => $until,
      ]);
      $message['body'][] = (string) $this->translation->translate('Картка не потрібна. Списань немає.');
      $message['body'][] = (string) $this->translation->translate('Збережені зрізи й відповіді лишаються.');
    }
  }

}
