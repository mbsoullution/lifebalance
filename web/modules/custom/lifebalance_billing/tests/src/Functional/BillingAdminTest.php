<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Functional;

use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Service\FakeGateway;
use Drupal\lifebalance_billing\Service\PaymentProcessor;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Finance screens, letters, and purchase links.
 */
#[Group('lifebalance_billing')]
#[RunTestsInSeparateProcesses]
final class BillingAdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'lifebalance',
    'lifebalance_billing',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A refund is a full return. The list hides the mail address.
   */
  public function testRefundLetterAndOrderList(): void {
    $this->drupalGet('/admin/lifebalance/orders');
    $this->assertSession()->statusCodeEquals(403);

    $member = $this->drupalCreateUser();
    $member->setEmail('payer-hidden@example.com');
    $member->save();
    $support = $this->drupalCreateUser();
    $support->addRole('support_manager');
    $support->save();
    $finance = $this->drupalCreateUser();
    $finance->addRole('finance_manager');
    $finance->save();
    $site = $this->drupalCreateUser();
    $site->addRole('site_manager');
    $site->save();

    $this->drupalLogin($member);
    $this->drupalGet('/premium/checkout/premium_30');
    $this->submitForm([], 'Перейти до оплати');
    $order = $this->onlyOrder((int) $member->id());
    $raw = $this->paidBody($order, 2_000_000_100);
    $gateway = $this->container->get(FakeGateway::class);
    $this->assertSame(200, $this->postWebhook($raw, $gateway->sign($raw)));
    $this->assertSame(200, $this->postWebhook($raw, $gateway->sign($raw)));
    $paid_mails = $this->mails('lifebalance_billing_payment_paid');
    $this->assertCount(1, $paid_mails);
    $this->assertSame('Оплату зараховано', $paid_mails[0]['subject']);
    $this->assertStringNotContainsString('payer-hidden@example.com', $this->body($paid_mails[0]));
    $this->assertStringContainsString('149,00 грн', $this->body($paid_mails[0]));
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_PAID, 2_000_000_100);

    $this->drupalLogin($support);
    $this->drupalGet('/admin/lifebalance/orders');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/lifebalance/users/' . $member->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Замовлення');
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->pageTextContains('Акаунти:');
    $this->assertSession()->pageTextContains('Зареєстровані:');
    $this->assertSession()->pageTextNotContains('Виручка:');
    $this->assertSession()->pageTextNotContains('Купили:');

    $this->drupalLogin($finance);
    $this->drupalGet('/admin/lifebalance/users/' . $member->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/lifebalance/orders');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->pageTextContains('Оплачено');
    $this->assertSession()->pageTextContains('149,00 грн');
    $this->assertSession()->pageTextNotContains('payer-hidden@example.com');
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->pageTextContains('Виручка: 149,00 грн');
    $this->assertSession()->pageTextContains('Оплати, 30 днів: 1');
    $this->assertSession()->pageTextContains('Відкрили оплату: 1');
    $this->assertSession()->pageTextContains('Купили: 1');
    $this->assertSession()->pageTextContains('Продовжили: 0');
    $this->assertSession()->pageTextNotContains('Акаунти:');

    $this->drupalGet('/admin/lifebalance/orders/' . $order->uuid() . '/refund');
    $this->submitForm([], 'Повернути');
    $this->assertSession()->pageTextContains('Оплату повернено.');
    $this->assertSession()->pageTextContains('Повернено');
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $storage->resetCache();
    $entitlement = $storage->load($this->reload($order)->getEntitlementId());
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $this->assertFalse($checker->hasPremium($member, $entitlement->getStarts()));
    $refund_mails = $this->mails('lifebalance_billing_payment_refunded');
    $this->assertCount(1, $refund_mails);
    $this->assertStringContainsString('Доступ із цієї оплати завершено.', $this->body($refund_mails[0]));
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->pageTextContains('Виручка: 0,00 грн');
    $this->assertSession()->pageTextContains('Купили: 0');

    $this->drupalLogin($site);
    $this->drupalGet('/admin/lifebalance/orders');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A partial refund waits for a decision. A chargeback revokes immediately.
   */
  public function testPartialDecisionAndChargeback(): void {
    $member = $this->drupalCreateUser();
    $finance = $this->drupalCreateUser();
    $finance->addRole('finance_manager');
    $finance->save();
    $this->drupalLogin($member);
    $this->drupalGet('/premium/checkout/premium_30');
    $this->submitForm([], 'Перейти до оплати');
    $order = $this->onlyOrder((int) $member->id());
    $gateway = $this->container->get(FakeGateway::class);
    $this->assertSame(200, $this->postWebhook($this->paidBody($order, 2_000_000_100), $gateway->sign($this->paidBody($order, 2_000_000_100))));
    $gateway->setRemoteStatus($order->getInvoiceId(), PaymentOrder::STATUS_PARTIALLY_REFUNDED, 2_000_000_200);
    $event = $gateway->getStatus($order->getInvoiceId());
    $this->assertNotNull($event);
    $this->container->get(PaymentProcessor::class)->apply($event, 'status_check');

    $this->drupalLogin($finance);
    $this->drupalGet('/admin/lifebalance/orders/' . $order->uuid() . '/partial');
    $this->submitForm(['reason' => '   '], 'Скасувати доступ');
    $this->assertSession()->pageTextContains('Вкажіть причину.');
    $this->submitForm(['reason' => 'Частину повернуто, доступ завершено'], 'Скасувати доступ');
    $this->assertSession()->pageTextContains('Доступ із цієї оплати завершено.');
    $this->assertSession()->pageTextNotContains('Частину повернуто, доступ завершено');
    $reloaded = $this->reload($order);
    $this->assertSame(PaymentOrder::STATUS_PARTIALLY_REFUNDED, $reloaded->getStatus());
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $storage->resetCache();
    $entitlement = $storage->load($reloaded->getEntitlementId());
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $this->assertFalse($checker->hasPremium($member, $entitlement->getStarts()));

    $this->drupalLogin($member);
    $this->drupalGet('/premium/checkout/premium_30');
    $this->submitForm([], 'Перейти до оплати');
    $second = $this->newestOrder((int) $member->id());
    $this->assertSame(200, $this->postWebhook($this->paidBody($second, 2_000_000_300), $gateway->sign($this->paidBody($second, 2_000_000_300))));
    $this->drupalLogin($finance);
    $this->drupalGet('/admin/lifebalance/orders/' . $second->uuid() . '/chargeback');
    $this->submitForm([], 'Позначити спір');
    $this->assertSession()->pageTextContains('Спір позначено, доступ завершено.');
    $this->assertSame(PaymentOrder::STATUS_CHARGEBACK, $this->reload($second)->getStatus());
    $second_access = $storage->load($this->reload($second)->getEntitlementId());
    $this->assertInstanceOf(Entitlement::class, $second_access);
    $this->assertFalse($checker->hasPremium($member, $second_access->getStarts()));
  }

  /**
   * The public page links to checkout, except when access has no end.
   */
  public function testPremiumPurchaseLinks(): void {
    $this->config('lifebalance.settings')->set('premium_enabled', TRUE)->save();
    $this->drupalGet('/premium');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Оплату приймає платіжна сторінка.');
    $this->assertSession()->pageTextContains('Автопродовження немає.');
    $this->assertSession()->pageTextContains('Річна ціна 1190,00 грн за 365 днів.');
    $this->assertSession()->pageTextContains('Поділено на 12, це близько 99,17 грн за 30 днів.');
    $this->assertSession()->pageTextContains('Основа порівняння: 12 оплат по 149,00 грн.');
    $this->assertSession()->pageTextContains('Разом 1788,00 грн. Різниця близько 33%.');
    $this->assertSession()->linkExists('Умови повернення');
    $this->assertSession()->linkExists('Оплатити: 365 днів — 1190,00 грн');
    $this->clickLink('Оплатити: 30 днів — 149,00 грн');
    $this->assertSession()->addressMatches('#/user/login#');

    $open = $this->drupalCreateUser();
    $this->container->get(EntitlementManager::class)->grant(
      $open,
      Entitlement::SOURCE_ADMIN,
      1_700_000_000,
      NULL,
      'Безстроково',
      $open,
      FALSE,
    );
    $this->drupalLogin($open);
    $this->drupalGet('/premium');
    $this->assertSession()->pageTextContains('Безстроковий доступ уже є, тож нова оплата не потрібна.');
    $this->assertSession()->pageTextNotContains('Оплатити:');
  }

  /**
   * Signed JSON for a paid notice.
   */
  private function paidBody(PaymentOrder $order, int $modified): string {
    $raw = json_encode([
      'invoiceId' => $order->getInvoiceId(),
      'status' => PaymentOrder::STATUS_PAID,
      'amount' => $order->getAmountMinor(),
      'currency' => $order->getCurrency(),
      'reference' => $order->uuid(),
      'modifiedDate' => $modified,
    ]);
    $this->assertIsString($raw);
    return $raw;
  }

  /**
   * Posts one raw notice and returns the HTTP status.
   */
  private function postWebhook(string $raw, string $signature): int {
    $response = $this->getHttpClient()->request('POST', $this->buildUrl('/billing/webhook/fake'), [
      'headers' => [
        'Content-Type' => 'application/json',
        FakeGateway::SIGNATURE_HEADER => $signature,
      ],
      'body' => $raw,
      'http_errors' => FALSE,
    ]);
    return $response->getStatusCode();
  }

  /**
   * The only order of this owner.
   */
  private function onlyOrder(int $uid): PaymentOrder {
    $orders = $this->ordersOf($uid);
    $this->assertCount(1, $orders);
    return $orders[0];
  }

  /**
   * The newest order of this owner.
   */
  private function newestOrder(int $uid): PaymentOrder {
    $orders = $this->ordersOf($uid);
    $this->assertNotSame([], $orders);
    return $orders[0];
  }

  /**
   * Orders of one account, newest id first.
   *
   * @return list<\Drupal\lifebalance_billing\Entity\PaymentOrder>
   *   Stored orders.
   */
  private function ordersOf(int $uid): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('id', 'DESC')
      ->execute();
    $loaded = $storage->loadMultiple($ids);
    $orders = [];
    foreach ($ids as $id) {
      $order = $loaded[$id] ?? NULL;
      if ($order instanceof PaymentOrder) {
        $orders[] = $order;
      }
    }
    return $orders;
  }

  /**
   * Reloads one order from storage.
   */
  private function reload(PaymentOrder $order): PaymentOrder {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $storage->resetCache([(int) $order->id()]);
    $fresh = $storage->load($order->id());
    $this->assertInstanceOf(PaymentOrder::class, $fresh);
    return $fresh;
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
   * Letter body as one string.
   *
   * @param array<string, mixed> $mail
   *   One collected message.
   */
  private function body(array $mail): string {
    $body = $mail['body'] ?? '';
    if (is_array($body)) {
      return implode("\n", array_map('strval', $body));
    }
    return is_string($body) ? $body : '';
  }

}
