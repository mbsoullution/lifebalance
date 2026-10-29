<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\EffectiveStatus;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementNotifier;
use Drupal\lifebalance\SocialAuth\ProviderLinks;
use Drupal\user\UserDataInterface;

/**
 * Connect and disconnect social providers for the current person.
 */
final class AccountDataForm extends FormBase {

  public function __construct(
    protected ProviderLinks $providerLinks,
    protected RouteProviderInterface $routeProvider,
    protected EntitlementCheckerInterface $entitlement,
    protected DateFormatterInterface $dateFormatter,
    protected UserDataInterface $userData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_account_data';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $account = $this->currentUser();
    $records = $this->providerLinks->records($account);
    $linked = [];
    $items = [];
    foreach ($records as $record) {
      $plugin = (string) $record->get('plugin_id')->value;
      $linked[$plugin] = TRUE;
      $items[] = $this->label($plugin);
    }
    $uid = (int) $account->id();
    $meta = $this->entitlement->getCacheability($account);
    $form['#cache'] = [
      'contexts' => $meta->getCacheContexts(),
      'tags' => array_merge(
        ['social_auth_list', 'user:' . $uid],
        $meta->getCacheTags(),
      ),
      'max-age' => $meta->getCacheMaxAge(),
    ];
    $form['premium_status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->premiumStatus($account),
    ];
    $sources = $this->sourceLine($account);
    if ($sources !== '') {
      $form['sources'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $sources,
      ];
    }
    $stored = $this->userData->get('lifebalance', $uid, EntitlementNotifier::MUTE_NAME);
    $form['mute'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Не надсилати сервісні нагадування'),
      '#default_value' => $stored === 1 || $stored === '1',
    ];
    $form['save_reminders'] = [
      '#type' => 'submit',
      '#name' => 'save_reminders',
      '#value' => $this->t('Зберегти нагадування'),
    ];
    $form['lead'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t(
        "Прив'яжіть або відв'яжіть Google і Facebook. Останній спосіб входу лишається.",
      ),
    ];
    $form['providers'] = [
      '#theme' => 'item_list',
      '#items' => $items === [] ? [$this->t("Провайдери ще не прив'язані.")] : $items,
    ];
    if ($this->routeProvider->getRoutesByNames(['social_auth.network.redirect']) !== []) {
      foreach (['google' => 'social_auth_google', 'facebook' => 'social_auth_facebook'] as $network => $plugin) {
        if (isset($linked[$plugin])) {
          continue;
        }
        $form['connect'][$network] = [
          '#type' => 'link',
          '#title' => $network === 'google' ? $this->t("Прив'язати Google") : $this->t("Прив'язати Facebook"),
          '#url' => Url::fromRoute('social_auth.network.redirect', ['network' => $network]),
        ];
      }
    }
    $form['export_lead'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Завантажте копію своїх зрізів, чернетки й нотаток.'),
    ];
    $form['export_json'] = [
      '#type' => 'link',
      '#title' => $this->t('Завантажити JSON'),
      '#url' => Url::fromRoute('lifebalance.export_json'),
    ];
    $form['export_csv'] = [
      '#type' => 'link',
      '#title' => $this->t('Завантажити CSV'),
      '#url' => Url::fromRoute('lifebalance.export_csv'),
    ];
    if ((int) $account->id() > 1) {
      $form['delete_account'] = [
        '#type' => 'link',
        '#title' => $this->t('Видалити акаунт'),
        '#url' => Url::fromRoute('lifebalance.account_delete'),
      ];
    }
    if ($records !== [] && !$this->providerLinks->canRemoveOne($account, count($records))) {
      $form['locked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t("Це останній спосіб входу. Відв'язати його не можна."),
      ];
      return $form;
    }
    foreach ($records as $record) {
      $id = (int) $record->id();
      $plugin = (string) $record->get('plugin_id')->value;
      $form['unlink'][$id] = [
        '#type' => 'submit',
        '#name' => 'unlink_' . $id,
        '#value' => $this->t("Відв'язати @provider", ['@provider' => $this->label($plugin)]),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $name = is_array($trigger) ? (string) ($trigger['#name'] ?? '') : '';
    if ($name === 'save_reminders') {
      $this->saveReminders($form_state);
      return;
    }
    if (preg_match('/^unlink_(\d+)$/', $name, $matches) !== 1) {
      return;
    }
    $removed = $this->providerLinks->unlink((int) $matches[1], $this->currentUser());
    if ($removed) {
      $this->messenger()->addStatus($this->t("Спосіб входу відв'язано."));
    }
    else {
      $this->messenger()->addError($this->t("Це останній спосіб входу. Відв'язати його не можна."));
    }
    $form_state->setRedirect('lifebalance.account_data');
  }

  /**
   * Access line for the current person, including the end when there is one.
   */
  private function premiumStatus(AccountInterface $account): string {
    $status = $this->entitlement->getEffectiveStatus($account);
    $closed = $status->status === EffectiveStatus::FREE;
    $closed = $closed || $status->status === EffectiveStatus::EXPIRED;
    if ($closed) {
      return (string) $this->t('Розширене оцінювання: недоступне');
    }
    if ($status->ends === NULL) {
      return (string) $this->t('Доступ безстроково');
    }
    $date = $this->dateFormatter->format($status->ends, 'custom', 'd.m.Y', 'Europe/Kyiv');
    return (string) $this->t('Доступ до @date включно', ['@date' => $date]);
  }

  /**
   * Active sources, or an empty string when access is not active.
   */
  private function sourceLine(AccountInterface $account): string {
    $status = $this->entitlement->getEffectiveStatus($account);
    $labels = [];
    foreach ($status->sources as $source) {
      $label = $this->sourceLabel($source);
      if ($label !== '') {
        $labels[] = $label;
      }
    }
    if ($labels === []) {
      return '';
    }
    return (string) $this->t('Джерела: @sources', [
      '@sources' => implode(', ', $labels),
    ]);
  }

  /**
   * Stores or clears the reminder refusal for the current person.
   */
  private function saveReminders(FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      return;
    }
    if ($form_state->getValue('mute')) {
      $this->userData->set('lifebalance', $uid, EntitlementNotifier::MUTE_NAME, 1);
    }
    else {
      $this->userData->delete('lifebalance', $uid, EntitlementNotifier::MUTE_NAME);
    }
    $this->messenger()->addStatus($this->t('Налаштування збережено.'));
    $form_state->setRedirect('lifebalance.account_data');
  }

  /**
   * Ukrainian label for one source key.
   */
  private function sourceLabel(string $source): string {
    return match ($source) {
      Entitlement::SOURCE_PAYMENT => (string) $this->t('Оплата'),
      Entitlement::SOURCE_ADMIN => (string) $this->t('Надання'),
      Entitlement::SOURCE_TRIAL => (string) $this->t('Ознайомлення'),
      Entitlement::SOURCE_PROMO => (string) $this->t('Промо'),
      default => '',
    };
  }

  /**
   * Visible name of a provider plugin.
   */
  private function label(string $plugin): string {
    return match ($plugin) {
      'social_auth_google' => (string) $this->t('Google'),
      'social_auth_facebook' => (string) $this->t('Facebook'),
      default => $plugin,
    };
  }

}
