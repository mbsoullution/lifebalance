<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Service\MinorMoney;
use Drupal\lifebalance_billing\Service\OrderManager;
use Drupal\lifebalance_billing\Service\PromoListConfirmException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts a purchase and sends the browser to the provider.
 *
 * The form token is required. This submit does not grant access.
 */
final class CheckoutForm extends FormBase {

  private const FLOOD_EVENT = 'lifebalance_billing.checkout';

  private const FLOOD_LIMIT = 10;

  private const FLOOD_WINDOW = 3600;

  public function __construct(
    protected OrderManager $orders,
    protected FloodInterface $flood,
    protected Clock $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OrderManager::class),
      $container->get('flood'),
      $container->get(Clock::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_billing_checkout';
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
    $plan = $this->plan();
    $account = $this->currentUser();
    $form['#cache']['contexts'][] = 'user';
    $form['#cache']['max-age'] = 0;
    $quote = $this->orders->quote($account, $plan);
    $form['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('@price грн за @days днів', [
        '@price' => MinorMoney::format($quote->amountMinor),
        '@days' => $plan->getDurationDays(),
      ]),
    ];
    if ($quote->amountMinor < $plan->getPriceMinor()) {
      $form['next_price'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Наступні 30 днів коштують @price грн.', [
          '@price' => MinorMoney::format($plan->getPriceMinor()),
        ]),
      ];
    }
    if ($quote->confirmList) {
      $form['ended'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Акційні місця закінчились. Ціна 149 грн за 30 днів'),
      ];
      $form['confirm_list'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Підтверджую оплату 149 грн за 30 днів'),
        '#required' => TRUE,
        '#required_error' => $this->t('Підтвердьте оплату 149 грн за 30 днів.'),
      ];
    }
    $window = $this->orders->accessWindow($account, $plan);
    if ($window !== NULL) {
      $form['until'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Доступ до @date включно.', [
          '@date' => $this->kyivDate($window['ends']),
        ]),
      ];
      if ($window['starts'] > $this->clock->now()) {
        $form['later'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Оплата почнеться @date, після поточного доступу.', [
            '@date' => $this->kyivDate($window['starts']),
          ]),
        ];
      }
    }
    $form['renewal'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Автопродовження немає.'),
    ];
    $form['kept'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Збережені зрізи, відповіді, цілі й плани лишаються.'),
    ];
    $form['closed'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Нові розширені дії будуть недоступні.'),
    ];
    $form['refunds'] = [
      '#type' => 'link',
      '#title' => $this->t('Умови повернення'),
      '#url' => Url::fromRoute('lifebalance.refunds'),
    ];
    if ($this->orders->blockedByOpenEnded($account)) {
      $form['blocked'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Безстроковий доступ уже є, тож нова оплата не потрібна.'),
      ];
      return $form;
    }
    $identifier = 'uid-' . $account->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      $form['flood'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Забагато спроб оплати. Спробуйте пізніше.'),
      ];
      return $form;
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Перейти до оплати'),
    ];
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
    $plan = $this->plan();
    $account = $this->currentUser();
    $identifier = 'uid-' . $account->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      $this->messenger()->addError($this->t('Забагато спроб оплати. Спробуйте пізніше.'));
      return;
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);
    if ($this->orders->blockedByOpenEnded($account)) {
      $this->messenger()->addError($this->t('Безстроковий доступ уже є, тож нова оплата не потрібна.'));
      return;
    }
    $accept = (bool) $form_state->getValue('confirm_list');
    try {
      $page = $this->orders->start($account, $plan, $accept);
    }
    catch (PromoListConfirmException) {
      $this->messenger()->addError($this->t('Акційні місця закінчились. Ціна 149 грн за 30 днів'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $form_state->setRedirectUrl(Url::fromUri($page));
  }

  /**
   * Calendar date in Europe/Kyiv.
   *
   * @param int $timestamp
   *   A unix instant.
   */
  private function kyivDate(int $timestamp): string {
    $zone = new \DateTimeZone('Europe/Kyiv');
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
    return $date->format('d.m.Y');
  }

  /**
   * Active plan from the route, or a 404.
   */
  private function plan(): BillingPlan {
    $id = $this->getRouteMatch()->getParameter('plan');
    $plan = is_string($id) ? $this->orders->loadPlan($id) : NULL;
    if (!$plan instanceof BillingPlan) {
      throw new NotFoundHttpException();
    }
    return $plan;
  }

}
