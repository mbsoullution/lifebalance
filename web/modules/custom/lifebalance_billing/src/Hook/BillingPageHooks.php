<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Service\BillingStats;
use Drupal\lifebalance_billing\Service\MinorMoney;
use Drupal\lifebalance_billing\Service\PromoReservations;
use Drupal\lifebalance_billing\Service\OrderAdminList;
use Drupal\lifebalance_billing\Service\OrderTable;
use Drupal\user\UserInterface;

/**
 * Purchase links, the order block, and revenue lines.
 */
final class BillingPageHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntitlementManager $entitlements,
    private readonly AccountProxyInterface $currentUser,
    private readonly TranslationInterface $translation,
    private readonly OrderAdminList $orders,
    private readonly OrderTable $table,
    private readonly BillingStats $stats,
    private readonly PromoReservations $promos,
  ) {}

  /**
   * Adds purchase links when a plan is for sale and access is not open-ended.
   *
   * @param array<string, mixed> $build
   *   The premium page.
   */
  #[Hook('lifebalance_premium_page_alter')]
  public function premiumPage(array &$build): void {
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['max-age'] = 0;
    $plans = $this->plans();
    foreach ($plans as $plan) {
      $build['#cache']['tags'][] = 'config:lifebalance_billing.plan.' . $plan->id();
    }
    $uid = (int) $this->currentUser->id();
    if ($uid > 0) {
      $build['#cache']['tags'][] = 'lifebalance_entitlement:' . $uid;
    }
    if ($plans === []) {
      return;
    }
    if ($uid > 0 && $this->entitlements->nextPaidStart($this->currentUser) === NULL) {
      $build['invite'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->translation->translate('Безстроковий доступ уже є, тож нова оплата не потрібна.'),
      ];
      return;
    }
    $build['invite'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->translation->translate('Оплату приймає платіжна сторінка. Ця сторінка доступ не відкриває.'),
    ];
    $seats = $this->promos->publicSeats();
    if ($seats === 'many') {
      $build['seats'] = $this->line($this->translation->translate('Залишилось місць: багато.'));
    }
    elseif ($seats === 'few') {
      $build['seats'] = $this->line($this->translation->translate('Залишилось місць: менше 20.'));
    }
    elseif ($seats === 'none') {
      $build['seats'] = $this->line($this->translation->translate('Залишилось місць: закінчились.'));
    }
    if ($seats !== '') {
      $build['next_price'] = $this->line($this->translation->translate('Наступні 30 днів коштують 149,00 грн.'));
      $build['no_renew'] = $this->line($this->translation->translate('Автосписання немає.'));
    }
    $build['renewal'] = $this->line($this->translation->translate('Автопродовження немає.'));
    $build['refunds'] = [
      '#type' => 'link',
      '#title' => $this->translation->translate('Умови повернення'),
      '#url' => Url::fromRoute('lifebalance.refunds'),
    ];
    $this->yearBasis($build, $plans);
    $build['purchases'] = ['#type' => 'container'];
    foreach ($plans as $plan) {
      $build['purchases'][$plan->id()] = [
        '#type' => 'link',
        '#title' => $this->translation->translate('Оплатити: @label — @price грн', [
          '@label' => $plan->label(),
          '@price' => MinorMoney::format($plan->getPriceMinor()),
        ]),
        '#url' => Url::fromRoute('lifebalance_billing.checkout', ['plan' => $plan->id()]),
      ];
    }
  }

  /**
   * Adds an order block that stays separate from manual grants.
   *
   * @param array<string, mixed> $build
   *   The account card.
   * @param \Drupal\user\UserInterface $user
   *   The account on the card.
   */
  #[Hook('lifebalance_user_card_alter')]
  public function userCard(array &$build, UserInterface $user): void {
    if (!$this->currentUser->hasPermission('view lifebalance orders')) {
      return;
    }
    $build['orders'] = [
      '#type' => 'container',
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->translation->translate('Замовлення'),
      ],
      'table' => $this->table->build($this->orders->forOwner((int) $user->id())),
    ];
  }

  /**
   * Adds revenue. Manual grants are not part of the sum.
   *
   * @param array<string, mixed> $build
   *   The statistics page.
   * @param int $since
   *   Start of the selected period, or 0 for all time.
   */
  #[Hook('lifebalance_admin_stats_alter')]
  public function adminStats(array &$build, int $since): void {
    if (!$this->currentUser->hasPermission('view lifebalance stats')) {
      return;
    }
    $summary = $this->stats->summary($since);
    $build['checkout'] = $this->line($this->translation->translate('Відкрили оплату: @count', [
      '@count' => $summary['checkout_people'],
    ]));
    $build['bought'] = $this->line($this->translation->translate('Купили: @count', [
      '@count' => $summary['bought_people'],
    ]));
    $build['renewed'] = $this->line($this->translation->translate('Продовжили: @count', [
      '@count' => $summary['renewed_people'],
    ]));
    $build['revenue'] = $this->line($this->translation->translate('Виручка: @amount грн', [
      '@amount' => MinorMoney::format($summary['revenue_minor']),
    ]));
    foreach ($summary['plans'] as $plan) {
      $key = preg_replace('/[^a-z0-9_]/', '', $plan['id']) ?? '';
      if ($key === '') {
        continue;
      }
      $build['plan_' . $key] = $this->line($this->translation->translate('Оплати, @label: @count', [
        '@label' => $plan['label'],
        '@count' => $plan['count'],
      ]));
    }
    $build['conversion'] = $this->line($this->translation->translate('Оплатили: @paid з @accounts (@percent)', [
      '@paid' => $summary['paid_people'],
      '@accounts' => $summary['accounts'],
      '@percent' => $this->percent($summary['paid_people'], $summary['accounts']),
    ]));
  }

  /**
   * States the 365-day price against twelve 30-day purchases.
   *
   * @param array<string, mixed> $build
   *   The premium page.
   * @param list<\Drupal\lifebalance_billing\Entity\BillingPlan> $plans
   *   Active plans.
   */
  private function yearBasis(array &$build, array $plans): void {
    $thirty = NULL;
    $year = NULL;
    foreach ($plans as $plan) {
      if ($plan->id() === 'premium_30') {
        $thirty = $plan;
      }
      elseif ($plan->id() === 'premium_365') {
        $year = $plan;
      }
    }
    if (!$thirty instanceof BillingPlan || !$year instanceof BillingPlan) {
      return;
    }
    $thirty_price = $thirty->getPriceMinor();
    $year_price = $year->getPriceMinor();
    $twelve = $thirty_price * 12;
    if ($thirty_price < 1 || $year_price < 1 || $twelve < 1) {
      return;
    }
    $per = (int) round($year_price / 12);
    $share = (int) round(($twelve - $year_price) / $twelve * 100);
    $build['year'] = $this->line($this->translation->translate('Річна ціна @year грн за 365 днів.', [
      '@year' => MinorMoney::format($year_price),
    ]));
    $build['per'] = $this->line($this->translation->translate('Поділено на 12, це близько @per грн за 30 днів.', [
      '@per' => MinorMoney::format($per),
    ]));
    $build['basis'] = $this->line($this->translation->translate('Основа порівняння: 12 оплат по @price грн.', [
      '@price' => MinorMoney::format($thirty_price),
    ]));
    $build['diff'] = $this->line($this->translation->translate('Разом @total грн. Різниця близько @percent%.', [
      '@total' => MinorMoney::format($twelve),
      '@percent' => $share,
    ]));
  }

  /**
   * Active plans in weight order.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\BillingPlan>
   *   Plans that can be bought.
   */
  private function plans(): array {
    $storage = $this->entityTypeManager->getStorage('lifebalance_billing_plan');
    $plans = [];
    foreach ($storage->loadMultiple() as $plan) {
      if ($plan instanceof BillingPlan && $plan->status()) {
        $plans[] = $plan;
      }
    }
    usort($plans, [BillingPlan::class, 'sort']);
    return $plans;
  }

  /**
   * One statistics line.
   *
   * @param \Stringable|string $text
   *   The line.
   *
   * @return array<string, mixed>
   *   A paragraph.
   */
  private function line(\Stringable|string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
    ];
  }

  /**
   * One decimal percent, matching the other statistics lines.
   */
  private function percent(int $part, int $whole): string {
    if ($whole < 1) {
      return '0.0%';
    }
    return number_format(($part / $whole) * 100, 1, '.', '') . '%';
  }

}
