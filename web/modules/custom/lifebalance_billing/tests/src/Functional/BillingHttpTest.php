<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Functional;

use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Service\FakeGateway;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checkout and the return page are private. Only a signed notice grants access.
 */
#[Group('lifebalance_billing')]
#[RunTestsInSeparateProcesses]
final class BillingHttpTest extends BrowserTestBase {

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
   * Redirects, a return page that grants nothing, and a signed webhook.
   */
  public function testCheckoutReturnAndWebhook(): void {
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->addressMatches('#/user/login#');
    $this->drupalGet('/premium/return/22222222-2222-4222-8222-222222222299');
    $this->assertSession()->addressMatches('#/user/login#');

    $member = $this->drupalCreateUser();
    $other = $this->drupalCreateUser();
    $this->drupalLogin($member);
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('149,00 грн за 30 днів');
    $this->assertSession()->pageTextContains('Автопродовження немає.');
    $this->assertSession()->pageTextContains('включно.');
    $this->assertSession()->linkExists('Умови повернення');
    $this->assertSession()->pageTextContains('Збережені зрізи, відповіді, цілі й плани лишаються.');
    $this->submitForm([], 'Перейти до оплати');
    $this->assertSession()->addressMatches('#/premium/return/#');
    $this->assertSession()->pageTextContains('Перевіряємо оплату');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $checker = $this->container->get(EntitlementCheckerInterface::class);
    $this->assertFalse($checker->hasPremium($member));

    $order = $this->onlyOrder((int) $member->id());
    $this->drupalGet('/premium/return/' . $order->uuid() . '/status');
    $this->assertSession()->responseContains('"status":"pending"');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $this->drupalLogin($other);
    $this->drupalGet('/premium/return/' . $order->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Перевіряємо оплату');

    $this->drupalLogin($member);
    $raw = json_encode([
      'invoiceId' => $order->getInvoiceId(),
      'status' => PaymentOrder::STATUS_PAID,
      'amount' => $order->getAmountMinor(),
      'currency' => $order->getCurrency(),
      'reference' => $order->uuid(),
      'modifiedDate' => 2_000_000_100,
    ]);
    $this->assertIsString($raw);
    $gateway = $this->container->get(FakeGateway::class);
    $rejected = $this->postWebhook($raw, 'not-a-signature');
    $this->assertSame(401, $rejected);
    $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement')->resetCache();
    $this->assertFalse($checker->hasPremium($member));

    $accepted = $this->postWebhook($raw, $gateway->sign($raw));
    $this->assertSame(200, $accepted);
    $repeated = $this->postWebhook($raw, $gateway->sign($raw));
    $this->assertSame(200, $repeated);
    $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order')->resetCache([$order->id()]);
    $paid = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order')->load($order->id());
    $this->assertInstanceOf(PaymentOrder::class, $paid);
    $this->assertSame(PaymentOrder::STATUS_PAID, $paid->getStatus());
    $ids = $this->entitlementIds((int) $member->id());
    $this->assertCount(1, $ids);
    $entitlement = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement')->load(reset($ids));
    $this->assertInstanceOf(Entitlement::class, $entitlement);
    $this->assertTrue($checker->hasPremium($member, $entitlement->getStarts()));
    $this->drupalGet('/premium/return/' . $order->uuid());
    $this->assertSession()->pageTextContains('Оплату зараховано.');

    $unknown = $this->postWebhook($raw, $gateway->sign($raw), 'monobank');
    $this->assertSame(404, $unknown);

    $flood = $this->container->get('flood');
    for ($i = 0; $i < 9; $i++) {
      $flood->register('lifebalance_billing.checkout', 3600, 'uid-' . $member->id());
    }
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->pageTextContains('Забагато спроб оплати. Спробуйте пізніше.');
    $this->assertSession()->pageTextNotContains('Перейти до оплати');

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
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Безстроковий доступ уже є, тож нова оплата не потрібна.');
    $this->assertSession()->pageTextNotContains('Перейти до оплати');
  }

  /**
   * Posts one raw notice and returns the HTTP status.
   */
  private function postWebhook(string $raw, string $signature, string $provider = 'fake'): int {
    $response = $this->getHttpClient()->request('POST', $this->buildUrl('/billing/webhook/' . $provider), [
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
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    $this->assertCount(1, $ids);
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(PaymentOrder::class, $order);
    return $order;
  }

  /**
   * Entitlement ids for one account.
   *
   * @return list<int|string>
   *   Entity ids.
   */
  private function entitlementIds(int $uid): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_entitlement');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    return array_values($ids);
  }

}
