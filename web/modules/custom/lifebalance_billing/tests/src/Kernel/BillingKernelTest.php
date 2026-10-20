<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\BillingPlan;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Service\BillingStats;
use Drupal\lifebalance_billing\Service\FakeGateway;
use Drupal\lifebalance_billing\Service\OrderManager;
use Drupal\lifebalance_billing\Service\PaymentProcessor;
use Drupal\lifebalance_billing\Service\PromoListConfirmException;
use Drupal\lifebalance_billing\Service\ProviderEvent;
use Drupal\lifebalance_billing\Service\Reconciler;
use Psr\Log\AbstractLogger;
use Drupal\lifebalance_plans\Service\GoalManager;
use Drupal\lifebalance_plans\Service\GoalResult;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Signed notices grant access once. The return page is not involved.
 */
#[Group('lifebalance_billing')]
final class BillingKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Active sector IDs.
   *
   * @var list<string>
   */
  private const SECTORS = [
    'health',
    'relationships',
    'environment',
    'purpose',
    'finances',
    'self_development',
    'life_brightness',
    'spirituality',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'views',
    'lifebalance',
    'lifebalance_plans',
    'lifebalance_billing',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_entitlement');
    $this->installSchema('lifebalance', [
      'lifebalance_entitlement_log',
      'lifebalance_trial_usage',
    ]);
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installEntitySchema('lifebalance_goal');
    $this->installEntitySchema('lifebalance_plan');
    $this->installEntitySchema('lifebalance_payment_order');
    $this->installSchema('lifebalance_billing', [
      'lifebalance_billing_event',
      'lifebalance_billing_promo',
      'lifebalance_billing_promo_use',
    ]);
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'filter',
      'views',
      'lifebalance',
      'lifebalance_plans',
      'lifebalance_billing',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
  }

  /**
   * A paid notice grants one entitlement. A repeat does not grant another.
   */
  public function testPaidNoticeGrantsOnce(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $processor = $this->container->get(PaymentProcessor::class);
    $first = $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100), 'webhook');
    $second = $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100), 'webhook');
    $this->assertSame('applied', $first);
    $this->assertSame('ignored', $second);
    $this->assertSame(PaymentOrder::STATUS_PAID, $this->reload($order)->getStatus());
    $this->assertCount(1, $this->entitlements($owner));
    $this->assertTrue($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
  }

  /**
   * An older paid notice does not restore access after a refund.
   */
  public function testOlderPaidAfterRefundIsIgnored(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $processor = $this->container->get(PaymentProcessor::class);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100), 'webhook');
    $refunded = $processor->apply($this->notice($order, PaymentOrder::STATUS_REFUNDED, 2_000_000_300), 'webhook');
    $this->assertSame('applied', $refunded);
    $older = $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_200), 'webhook');
    $this->assertSame('ignored', $older);
    $this->assertSame(PaymentOrder::STATUS_REFUNDED, $this->reload($order)->getStatus());
    $this->assertFalse($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
    $records = $this->entitlements($owner);
    $this->assertCount(1, $records);
    $this->assertSame(Entitlement::STATUS_REVOKED, $records[0]->getStatus());
  }

  /**
   * A different amount fails the order and grants nothing.
   */
  public function testAmountMismatchFails(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $result = $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100, 1),
      'webhook',
    );
    $this->assertSame('mismatch', $result);
    $this->assertSame(PaymentOrder::STATUS_FAILED, $this->reload($order)->getStatus());
    $this->assertSame([], $this->entitlements($owner));
    $this->assertFalse($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
  }

  /**
   * Failed, expired, and still-pending notices grant nothing.
   */
  public function testUnpaidNoticesGrantNothing(): void {
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    foreach ([PaymentOrder::STATUS_FAILED, PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_PENDING] as $status) {
      $owner = $this->createUser();
      $order = $this->checkout($owner);
      $this->container->get(PaymentProcessor::class)->apply($this->notice($order, $status, 2_000_000_100), 'webhook');
      $this->assertSame($status, $this->reload($order)->getStatus());
      $this->assertFalse($checker->hasPremium($owner));
    }
  }

  /**
   * A missed webhook is applied only inside the reconciliation window.
   */
  public function testLostWebhookIsReconciled(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $created = $this->reload($order)->getCreatedTime();
    $clock = $this->container->get(Clock::class);
    $gateway = $this->container->get(FakeGateway::class);
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_PAID, 2_000_000_500);
    $reconciler = $this->container->get(Reconciler::class);

    $clock->set($created + 60);
    $this->assertSame(0, $reconciler->run());
    $this->assertSame(PaymentOrder::STATUS_PENDING, $this->reload($order)->getStatus());

    $clock->set($created + 360);
    $this->assertSame(1, $reconciler->run());
    $this->assertSame(PaymentOrder::STATUS_PAID, $this->reload($order)->getStatus());
    $this->assertCount(1, $this->entitlements($owner));

    $late = $this->checkout($this->createUser());
    $clock->set($this->reload($late)->getCreatedTime() + (8 * 86400));
    $gateway->setRemoteStatus($late->getInvoiceId(), PaymentOrder::STATUS_PAID, 2_000_000_900);
    $this->assertSame(0, $reconciler->run());
    $this->assertSame(PaymentOrder::STATUS_PENDING, $this->reload($late)->getStatus());
  }

  /**
   * An expired invoice is closed by reconciliation, without granting access.
   */
  public function testReconcilerClosesExpired(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $created = $this->reload($order)->getCreatedTime();
    $gateway = $this->container->get(FakeGateway::class);
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_EXPIRED, 2_000_000_500);
    $this->container->get(Clock::class)->set($created + 360);
    $this->assertSame(1, $this->container->get(Reconciler::class)->run());
    $this->assertSame(PaymentOrder::STATUS_EXPIRED, $this->reload($order)->getStatus());
    $this->assertCount(0, $this->entitlements($owner));
    $this->assertFalse($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
  }

  /**
   * A refund revokes only the payment. Trial and a manual grant stay.
   */
  public function testRefundLeavesTrialAndGrant(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $manager = $this->container->get(EntitlementManager::class);
    $trial = $manager->grant(
      $owner,
      Entitlement::SOURCE_TRIAL,
      $now,
      $manager->endOfDays($now, 7),
      'Ознайомлення',
      $owner,
      FALSE,
    );
    $manual = $manager->grant(
      $owner,
      Entitlement::SOURCE_ADMIN,
      $now,
      $manager->endOfDays($now, 30),
      'Ручне надання',
      $owner,
      FALSE,
    );
    $order = $this->checkout($owner);
    $processor = $this->container->get(PaymentProcessor::class);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, $now + 10), 'webhook');
    $processor->apply($this->notice($order, PaymentOrder::STATUS_REFUNDED, $now + 20), 'webhook');
    $payment = $this->paymentRecord($owner);
    $this->assertSame(Entitlement::STATUS_REVOKED, $payment->getStatus());
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $storage->resetCache();
    $trial = $storage->load($trial->id());
    $manual = $storage->load($manual->id());
    $this->assertInstanceOf(Entitlement::class, $trial);
    $this->assertInstanceOf(Entitlement::class, $manual);
    $this->assertSame(Entitlement::STATUS_ACTIVE, $trial->getStatus());
    $this->assertSame(Entitlement::STATUS_ACTIVE, $manual->getStatus());
    $this->assertTrue($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
  }

  /**
   * A webhook and a status check together still grant one entitlement.
   */
  public function testWebhookAndReconcileGrantOnce(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $created = $this->reload($order)->getCreatedTime();
    $gateway = $this->container->get(FakeGateway::class);
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_PAID, 2_000_000_100);
    $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100),
      'webhook',
    );
    $this->container->get(Clock::class)->set($created + 360);
    $this->container->get(Reconciler::class)->run();
    $this->assertCount(1, $this->entitlements($owner));
    $this->assertSame(PaymentOrder::STATUS_PAID, $this->reload($order)->getStatus());
  }

  /**
   * A purchase beside an active grant starts when that grant ends.
   */
  public function testStackedPurchaseStartsLater(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $ends = $now + (10 * 86400);
    $this->container->get(EntitlementManager::class)->grant(
      $owner,
      Entitlement::SOURCE_ADMIN,
      $now - 10,
      $ends,
      'Тестове надання',
      $owner,
      FALSE,
    );
    $order = $this->checkout($owner);
    $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, $now + 20),
      'webhook',
    );
    $paid = $this->paymentRecord($owner);
    $this->assertSame($ends, $paid->getStarts());
    $expected = $this->container->get(EntitlementManager::class)->endOfDays($ends, 30);
    $this->assertSame($expected, $paid->getEnds());
  }

  /**
   * A payment during trial starts after the trial. The next one extends it.
   */
  public function testPaymentDuringTrialThenExtends(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $manager = $this->container->get(EntitlementManager::class);
    $trial_ends = $manager->endOfDays($now, 7);
    $manager->grant(
      $owner,
      Entitlement::SOURCE_TRIAL,
      $now,
      $trial_ends,
      'Ознайомлення',
      $owner,
      FALSE,
    );
    $processor = $this->container->get(PaymentProcessor::class);
    $first = $this->checkout($owner);
    $this->assertSame('', $first->getPromoId());
    $this->assertSame($first->getAmountMinor(), $first->getListPriceMinor());
    $processor->apply($this->notice($first, PaymentOrder::STATUS_PAID, $now + 10), 'webhook');
    $paid = $this->paymentRecord($owner);
    $this->assertSame($trial_ends, $paid->getStarts());
    $first_ends = $paid->getEnds();
    $second = $this->checkout($owner);
    $processor->apply($this->notice($second, PaymentOrder::STATUS_PAID, $now + 20), 'webhook');
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $loaded = $storage->load($this->reload($second)->getEntitlementId());
    $this->assertInstanceOf(Entitlement::class, $loaded);
    $this->assertNotNull($first_ends);
    $this->assertSame($first_ends, $loaded->getStarts());
    $this->assertSame($manager->endOfDays($first_ends, 30), $loaded->getEnds());
  }

  /**
   * An open-ended grant blocks a new payment.
   */
  public function testOpenEndedBlocksPayment(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $this->container->get(EntitlementManager::class)->grant(
      $owner,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      NULL,
      'Безстроково',
      $owner,
      FALSE,
    );
    $plan = $this->container->get(OrderManager::class)->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $this->expectException(\InvalidArgumentException::class);
    $this->container->get(OrderManager::class)->start($owner, $plan);
  }

  /**
   * A refund revokes access and leaves the snapshot and the goal.
   */
  public function testRefundKeepsHistory(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $processor = $this->container->get(PaymentProcessor::class);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100), 'webhook');
    $this->setCurrentUser($owner);
    $this->storeSnapshot($owner);
    $goal = $this->container->get(GoalManager::class)->createOwn(
      $owner,
      'health',
      'Ціль після оплати',
      'Опис',
      ['Крок'],
      NULL,
    );
    $this->assertSame(GoalResult::SAVED, $goal->code);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_REFUNDED, 2_000_000_400), 'webhook');
    $this->assertFalse($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
    $snapshots = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot')->loadByProperties([
      'uid' => $owner->id(),
    ]);
    $goals = $this->container->get('entity_type.manager')->getStorage('lifebalance_goal')->loadByProperties([
      'uid' => $owner->id(),
    ]);
    $this->assertCount(1, $snapshots);
    $this->assertCount(1, $goals);
  }

  /**
   * Deleting the account keeps the order and clears its owner.
   */
  public function testAccountDeletionKeepsOrder(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $owner->delete();
    $reloaded = $this->reload($order);
    $this->assertSame(0, $reloaded->getOwnerId());
    $uid = $this->container->get('database')->select('lifebalance_payment_order', 'o')
      ->fields('o', ['uid'])
      ->condition('id', $order->id())
      ->execute()
      ?->fetchField();
    $this->assertNull($uid);
  }

  /**
   * A changed plan price does not rewrite an open order.
   */
  public function testPlanPriceChangeKeepsSnapshot(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $plan = BillingPlan::load('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $plan->set('price_minor', 20000);
    $plan->save();
    $result = $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, 2_000_000_100),
      'webhook',
    );
    $this->assertSame('applied', $result);
    $this->assertSame(14900, $this->reload($order)->getAmountMinor());
  }

  /**
   * Installed billing config does not carry a provider key or token.
   */
  public function testConfigOmitsProviderMaterial(): void {
    $storage = $this->container->get('config.storage');
    $names = $storage->listAll('lifebalance_billing');
    $this->assertNotSame([], $names);
    foreach ($names as $name) {
      $encoded = json_encode($storage->read($name));
      $this->assertIsString($encoded);
      $this->assertDoesNotMatchRegularExpression('/private_key|api_token|BEGIN /', $encoded);
    }
    $directory = dirname(__DIR__, 3) . '/config/install';
    $files = glob($directory . '/*.yml');
    $this->assertIsArray($files);
    $this->assertNotSame([], $files);
    foreach ($files as $file) {
      $contents = file_get_contents($file);
      $this->assertIsString($contents);
      $this->assertDoesNotMatchRegularExpression('/private_key|api_token|BEGIN /', $contents);
    }
  }

  /**
   * The event table stores a hash, not the raw notice.
   */
  public function testEventRowOmitsBody(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $marker = 'not-stored-marker';
    $body = json_encode([
      'invoiceId' => $order->getInvoiceId(),
      'status' => PaymentOrder::STATUS_PAID,
      'amount' => $order->getAmountMinor(),
      'currency' => $order->getCurrency(),
      'reference' => $order->uuid(),
      'modifiedDate' => 2_000_000_100,
      'extra' => $marker,
    ]);
    $this->assertIsString($body);
    $event = new ProviderEvent(
      $order->getInvoiceId(),
      PaymentOrder::STATUS_PAID,
      PaymentOrder::STATUS_PAID,
      $order->getAmountMinor(),
      $order->getCurrency(),
      $order->uuid(),
      2_000_000_100,
      hash('sha256', $body),
    );
    $this->container->get(PaymentProcessor::class)->apply($event, 'webhook');
    $row = $this->container->get('database')->select('lifebalance_billing_event', 'e')
      ->fields('e')
      ->execute()
      ?->fetchAssoc();
    $this->assertIsArray($row);
    $this->assertArrayNotHasKey('payload', $row);
    $this->assertStringNotContainsString($marker, implode(' ', array_map('strval', $row)));
  }

  /**
   * Cron applies a missed payment once and sends one letter.
   */
  public function testCronClosesLostWebhook(): void {
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $created = $this->reload($order)->getCreatedTime();
    $gateway = $this->container->get(FakeGateway::class);
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_PAID, 2_000_000_500);
    $this->container->get(Clock::class)->set($created + 360);
    $this->container->get('cron')->run();
    $this->assertSame(PaymentOrder::STATUS_PAID, $this->reload($order)->getStatus());
    $this->assertCount(1, $this->entitlements($owner));
    $mails = $this->mails('lifebalance_billing_payment_paid');
    $this->assertCount(1, $mails);
    $this->container->get('cron')->run();
    $this->assertCount(1, $this->entitlements($owner));
    $this->assertCount(1, $this->mails('lifebalance_billing_payment_paid'));
  }

  /**
   * Revenue follows paid orders. A manual grant never enters the sum.
   */
  public function testRevenueIgnoresManualGrant(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $this->container->get(EntitlementManager::class)->grant(
      $owner,
      Entitlement::SOURCE_ADMIN,
      $now - 10,
      $now + 86400,
      'Ручне надання',
      $owner,
      FALSE,
    );
    $stats = $this->container->get(BillingStats::class);
    $this->assertSame(0, $stats->summary(0)['revenue_minor']);
    $order = $this->checkout($owner);
    $processor = $this->container->get(PaymentProcessor::class);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_PAID, $now + 20), 'webhook');
    $this->assertSame(14900, $stats->summary(0)['revenue_minor']);
    $this->assertSame(1, $stats->summary(0)['paid_people']);
    $this->assertSame(1, $stats->summary(0)['checkout_people']);
    $this->assertSame(1, $stats->summary(0)['bought_people']);
    $this->assertSame(0, $stats->summary(0)['renewed_people']);
    $processor->apply($this->notice($order, PaymentOrder::STATUS_REFUNDED, $now + 30), 'webhook');
    $this->assertSame(0, $stats->summary(0)['revenue_minor']);
    $this->assertSame(0, $stats->summary(0)['paid_people']);
    $this->assertSame(1, $stats->summary(0)['checkout_people']);
    $this->assertSame(0, $stats->summary(0)['bought_people']);
    $this->assertCount(1, $this->mails('lifebalance_billing_payment_refunded'));
  }

  /**
   * Two paid orders are a renewal. The totals stay numeric.
   */
  public function testTwoPaidOrdersCountAsRenewed(): void {
    $owner = $this->createUser();
    $this->setCurrentUser($owner);
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $processor = $this->container->get(PaymentProcessor::class);
    $first = $this->checkout($owner);
    $processor->apply($this->notice($first, PaymentOrder::STATUS_PAID, $now + 10), 'webhook');
    $stats = $this->container->get(BillingStats::class);
    $this->assertSame(0, $stats->summary(0)['renewed_people']);
    $second = $this->checkout($owner);
    $this->container->get(Clock::class)->set($now + 20);
    $processor->apply($this->notice($second, PaymentOrder::STATUS_PAID, $now + 20), 'webhook');
    $this->assertSame(1, $stats->summary(0)['renewed_people']);
    $this->assertSame(1, $stats->summary(0)['bought_people']);
    $this->assertSame(1, $stats->summary($now + 15)['renewed_people']);
    $this->assertSame(0, $stats->summary($now + 21)['renewed_people']);
    $encoded = json_encode($stats->summary(0));
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('payer', $encoded);
  }

  /**
   * The finance role can see orders and revenue, and cannot grant access.
   */
  public function testFinanceRolePermissions(): void {
    $role = Role::load('finance_manager');
    $this->assertNotNull($role);
    $this->assertEqualsCanonicalizing([
      'refund lifebalance orders',
      'view lifebalance orders',
      'view lifebalance stats',
    ], $role->getPermissions());
  }

  /**
   * The last slot is 99 грн. The next person confirms 149 грн explicitly.
   */
  public function testFullPromoDoesNotChangeThePriceQuietly(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $this->enablePromo(1, 0);
    $first = $this->createUser();
    $order = $this->checkout($first);
    $this->assertSame(9900, $order->getAmountMinor());
    $this->assertSame(14900, $order->getListPriceMinor());
    $this->assertSame('launch99', $order->getPromoId());
    $gateway = $this->container->get(FakeGateway::class);
    $this->assertSame(1800, $gateway->validityOf($order->getInvoiceId()));
    $until = $this->container->get('database')->select('lifebalance_billing_promo_use', 'u')
      ->fields('u', ['reserved_until'])
      ->condition('uid', $first->id())
      ->execute()
      ?->fetchField();
    $this->assertSame($now + 2100, (int) $until);

    $second = $this->createUser();
    $manager = $this->container->get(OrderManager::class);
    $plan = $manager->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $quote = $manager->quote($second, $plan);
    $this->assertSame(14900, $quote->amountMinor);
    $this->assertTrue($quote->confirmList);
    try {
      $manager->start($second, $plan, FALSE);
      $this->fail('A full promo created an order.');
    }
    catch (PromoListConfirmException) {
      $this->assertSame([], $this->orderIds($second));
    }
    $page = $manager->start($second, $plan, TRUE);
    $catalog = $this->newestOrder($second);
    $this->assertSame(14900, $catalog->getAmountMinor());
    $this->assertSame('', $catalog->getPromoId());
    $this->assertSame(86400, $gateway->validityOf($catalog->getInvoiceId()));
    $this->assertStringContainsString($catalog->uuid(), $page);
  }

  /**
   * One hundred paid slots leave the next buyer on the catalog price.
   */
  public function testHundredAndFirstPaysTheCatalogPrice(): void {
    $this->enablePromo(100, 100);
    $buyer = $this->createUser();
    $manager = $this->container->get(OrderManager::class);
    $plan = $manager->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $this->assertSame('none', $manager->quote($buyer, $plan)->seats);
    try {
      $manager->start($buyer, $plan, FALSE);
      $this->fail('The 101st buyer received the promo.');
    }
    catch (PromoListConfirmException) {
      $this->assertSame([], $this->orderIds($buyer));
    }
    $manager->start($buyer, $plan, TRUE);
    $this->assertSame(14900, $this->newestOrder($buyer)->getAmountMinor());
  }

  /**
   * A second click keeps the reserved order. A paid promo is not offered again.
   */
  public function testPromoStaysOnce(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $this->enablePromo();
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $manager = $this->container->get(OrderManager::class);
    $plan = $manager->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $again = $manager->start($owner, $plan, FALSE);
    $this->assertCount(1, $this->orderIds($owner));
    $this->assertStringContainsString($order->uuid(), $again);
    $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, $now + 10),
      'webhook',
    );
    $this->assertSame('paid', $this->promoState((int) $owner->id()));
    $this->assertSame(1, $this->paidCount());
    $manager->start($owner, $plan, FALSE);
    $second = $this->newestOrder($owner);
    $this->assertSame(14900, $second->getAmountMinor());
    $this->assertSame('', $second->getPromoId());
  }

  /**
   * An expired invoice frees the slot for the next person.
   */
  public function testExpiredPromoReleasesTheSlot(): void {
    $this->enablePromo(1, 0);
    $first = $this->createUser();
    $order = $this->checkout($first);
    $created = $this->reload($order)->getCreatedTime();
    $gateway = $this->container->get(FakeGateway::class);
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_EXPIRED, 2_000_000_500);
    $this->container->get(Clock::class)->set($created + 360);
    $this->container->get(Reconciler::class)->run();
    $this->assertSame('released', $this->promoState((int) $first->id()));
    $second = $this->createUser();
    $next = $this->checkout($second);
    $this->assertSame(9900, $next->getAmountMinor());
    $this->assertSame('launch99', $next->getPromoId());
  }

  /**
   * A payment after the hold still grants access and alerts the admin.
   */
  public function testLatePromoPaymentStillGrants(): void {
    $now = 1_700_000_000;
    $this->container->get(Clock::class)->set($now);
    $this->enablePromo();
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $this->container->get('database')->update('lifebalance_billing_promo_use')
      ->fields(['reserved_until' => $now - 10])
      ->condition('uid', $owner->id())
      ->execute();
    $logger = new class () extends AbstractLogger {

      /**
       * Logged messages.
       *
       * @var list<string>
       */
      public array $lines = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = (string) $message;
      }

    };
    $this->container->get('logger.factory')->get('lifebalance_billing')->addLogger($logger);
    $this->container->get(PaymentProcessor::class)->apply(
      $this->notice($order, PaymentOrder::STATUS_PAID, $now + 10),
      'webhook',
    );
    $this->assertTrue($this->container->get(EntitlementCheckerInterface::class)->hasPremium($owner));
    $this->assertSame(1, $this->paidCount());
    $this->assertContains('Late promo payment for order @id.', $logger->lines);
  }

  /**
   * Switching the promo off leaves an open reservation and blocks new ones.
   */
  public function testDisabledPromoKeepsAnOpenReservation(): void {
    $this->enablePromo(100, 0);
    $owner = $this->createUser();
    $order = $this->checkout($owner);
    $this->container->get('database')->update('lifebalance_billing_promo')
      ->fields(['active' => 0])
      ->condition('id', 'launch99')
      ->execute();
    $manager = $this->container->get(OrderManager::class);
    $plan = $manager->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $again = $manager->start($owner, $plan, FALSE);
    $this->assertCount(1, $this->orderIds($owner));
    $this->assertStringContainsString($order->uuid(), $again);
    $other = $this->createUser();
    $catalog = $this->checkout($other);
    $this->assertSame(14900, $catalog->getAmountMinor());
    $this->assertSame('', $catalog->getPromoId());
  }

  /**
   * Collected letters of one id.
   *
   * @return list<array<string, mixed>>
   *   Matching messages.
   */
  private function mails(string $id): array {
    $captured = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertIsArray($captured);
    $matched = [];
    foreach ($captured as $mail) {
      if (is_array($mail) && ($mail['id'] ?? '') === $id) {
        $matched[] = $mail;
      }
    }
    return $matched;
  }

  /**
   * Starts a pending order for one person.
   */
  private function checkout(UserInterface $owner): PaymentOrder {
    $manager = $this->container->get(OrderManager::class);
    $plan = $manager->loadPlan('premium_30');
    $this->assertInstanceOf(BillingPlan::class, $plan);
    $manager->start($owner, $plan);
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(PaymentOrder::class, $order);
    return $order;
  }

  /**
   * A mapped notice for this order.
   */
  private function notice(PaymentOrder $order, string $status, int $modified, ?int $amount = NULL): ProviderEvent {
    $amount_minor = $amount ?? $order->getAmountMinor();
    $body = json_encode([
      'invoiceId' => $order->getInvoiceId(),
      'status' => $status,
      'amount' => $amount_minor,
      'currency' => $order->getCurrency(),
      'reference' => $order->uuid(),
      'modifiedDate' => $modified,
    ]);
    $this->assertIsString($body);
    return new ProviderEvent(
      $order->getInvoiceId(),
      $status,
      $status,
      $amount_minor,
      $order->getCurrency(),
      $order->uuid(),
      $modified,
      hash('sha256', $body),
    );
  }

  /**
   * The order as stored now.
   */
  private function reload(PaymentOrder $order): PaymentOrder {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $storage->resetCache([$order->id()]);
    $reloaded = $storage->load($order->id());
    $this->assertInstanceOf(PaymentOrder::class, $reloaded);
    return $reloaded;
  }

  /**
   * Entitlements of one account.
   *
   * @return list<\Drupal\lifebalance\Entity\Entitlement>
   *   Matching records.
   */
  private function entitlements(UserInterface $owner): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $storage->resetCache();
    $records = [];
    foreach ($storage->loadByProperties(['uid' => $owner->id()]) as $record) {
      if ($record instanceof Entitlement) {
        $records[] = $record;
      }
    }
    return $records;
  }

  /**
   * The payment entitlement of one account.
   */
  private function paymentRecord(UserInterface $owner): Entitlement {
    foreach ($this->entitlements($owner) as $record) {
      if ($record->getSource() === Entitlement::SOURCE_PAYMENT) {
        return $record;
      }
    }
    $this->fail('The payment entitlement is missing.');
  }

  /**
   * Stores one snapshot owned by this person.
   */
  private function storeSnapshot(UserInterface $owner): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 5,
      ];
    }
    Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Нотатка оплати',
      'scores' => $scores,
    ])->save();
  }

  /**
   * Inserts an active launch99 row.
   */
  private function enablePromo(int $limit = 100, int $paid = 0): void {
    $this->container->get('database')->insert('lifebalance_billing_promo')
      ->fields([
        'id' => 'launch99',
        'active' => 1,
        'slot_limit' => $limit,
        'paid_count' => $paid,
        'starts' => 0,
        'ends' => 0,
        'price_minor' => 9900,
      ])
      ->execute();
  }

  /**
   * Order ids of one person, newest first.
   *
   * @return list<int|string>
   *   Matching ids.
   */
  private function orderIds(UserInterface $owner): array {
    $ids = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner->id())
      ->sort('id', 'DESC')
      ->execute();
    return array_values($ids);
  }

  /**
   * The newest order of one person.
   */
  private function newestOrder(UserInterface $owner): PaymentOrder {
    $ids = $this->orderIds($owner);
    $this->assertNotSame([], $ids);
    $order = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order')->load($ids[0]);
    $this->assertInstanceOf(PaymentOrder::class, $order);
    return $order;
  }

  /**
   * Promo state for one person.
   */
  private function promoState(int $uid): string {
    $state = $this->container->get('database')->select('lifebalance_billing_promo_use', 'u')
      ->fields('u', ['state'])
      ->condition('uid', $uid)
      ->execute()
      ?->fetchField();
    return is_string($state) ? $state : '';
  }

  /**
   * Paid promo slots.
   */
  private function paidCount(): int {
    $count = $this->container->get('database')->select('lifebalance_billing_promo', 'p')
      ->fields('p', ['paid_count'])
      ->condition('id', 'launch99')
      ->execute()
      ?->fetchField();
    return is_numeric($count) ? (int) $count : 0;
  }

}
