<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Functional;

use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The promo screen shows the same price the invoice will use.
 */
#[Group('lifebalance_billing')]
#[RunTestsInSeparateProcesses]
final class PromoCheckoutTest extends BrowserTestBase {

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
   * A free slot shows 99 грн. A full promo asks before 149 грн.
   */
  public function testShownPriceMatchesTheInvoice(): void {
    $this->drupalGet('/admin/lifebalance/promo');
    $this->assertSession()->statusCodeEquals(403);
    $finance = $this->drupalCreateUser();
    $finance->addRole('finance_manager');
    $finance->save();
    $this->drupalLogin($finance);
    $this->drupalGet('/admin/lifebalance/promo');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer lifebalance orders']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/lifebalance/promo');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Оплачено місць');
    $this->submitForm([
      'active' => TRUE,
      'slot_limit' => 100,
    ], 'Зберегти');
    $this->assertSession()->pageTextContains('Акцію збережено.');

    $this->config('lifebalance.settings')->set('premium_enabled', TRUE)->save();
    $buyer = $this->drupalCreateUser();
    $this->drupalLogin($buyer);
    $this->drupalGet('/premium');
    $this->assertSession()->pageTextContains('Залишилось місць: багато.');
    $this->assertSession()->pageTextContains('Наступні 30 днів коштують 149,00 грн.');
    $this->assertSession()->pageTextContains('Автосписання немає.');
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->pageTextContains('99,00 грн за 30 днів');
    $this->assertSession()->pageTextContains('Наступні 30 днів коштують 149,00 грн.');
    $this->submitForm([], 'Перейти до оплати');
    $order = $this->onlyOrder((int) $buyer->id());
    $this->assertSame(9900, $order->getAmountMinor());
    $this->assertSame('launch99', $order->getPromoId());

    $this->drupalLogin($admin);
    $this->drupalGet('/admin/lifebalance/promo');
    $this->submitForm([
      'active' => TRUE,
      'slot_limit' => 1,
    ], 'Зберегти');
    $next = $this->drupalCreateUser();
    $this->drupalLogin($next);
    $this->drupalGet('/premium/checkout/premium_30');
    $this->assertSession()->pageTextContains('Акційні місця закінчились. Ціна 149 грн за 30 днів');
    $this->assertSession()->pageTextContains('149,00 грн за 30 днів');
    $this->submitForm([], 'Перейти до оплати');
    $this->assertSession()->pageTextContains('Підтвердьте оплату 149 грн за 30 днів.');
    $this->submitForm([
      'confirm_list' => TRUE,
    ], 'Перейти до оплати');
    $catalog = $this->onlyOrder((int) $next->id());
    $this->assertSame(14900, $catalog->getAmountMinor());
    $this->assertSame('', $catalog->getPromoId());
  }

  /**
   * The only order of this owner.
   */
  private function onlyOrder(int $uid): PaymentOrder {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_payment_order');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();
    $this->assertCount(1, $ids);
    $order = $storage->load(reset($ids));
    $this->assertInstanceOf(PaymentOrder::class, $order);
    return $order;
  }

}
