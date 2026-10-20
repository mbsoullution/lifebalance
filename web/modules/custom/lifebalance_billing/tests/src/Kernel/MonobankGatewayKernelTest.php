<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_billing\Kernel;

use Drupal\Core\State\StateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Service\FakeGateway;
use Drupal\lifebalance_billing\Service\MonobankGateway;
use Drupal\lifebalance_billing\Service\PaymentGatewayInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;

/**
 * The monobank adapter speaks only the documented merchant API.
 */
#[Group('lifebalance_billing')]
final class MonobankGatewayKernelTest extends KernelTestBase {

  /**
   * Token used only inside this test.
   */
  private const TOKEN = 'unit-token';

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
   * Recorded HTTP calls.
   *
   * @var list<array<string, mixed>>
   */
  private array $history = [];

  /**
   * Without a token the site keeps the local gateway.
   */
  public function testActiveGatewayStaysFake(): void {
    $gateway = $this->container->get(PaymentGatewayInterface::class);
    $this->assertInstanceOf(FakeGateway::class, $gateway);
  }

  /**
   * Invoice creation sends the documented fields and nothing about a card.
   */
  public function testInvoiceCreateUsesDocumentedFields(): void {
    $gateway = $this->gateway([
      new Response(200, [], '{"invoiceId":"p2_test","pageUrl":"https://pay.mbnk.biz/p2_test"}'),
    ]);
    $result = $gateway->createInvoice(14900, 'UAH', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'https://example.test/return', 'https://example.test/hook');
    $this->assertSame('p2_test', $result->invoiceId);
    $this->assertSame('https://pay.mbnk.biz/p2_test', $result->pageUrl);
    $request = $this->recorded(0);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/api/merchant/invoice/create', $request->getUri()->getPath());
    $this->assertSame(self::TOKEN, $request->getHeaderLine('X-Token'));
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame([
      'amount' => 14900,
      'ccy' => 980,
      'merchantPaymInfo' => [
        'reference' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'destination' => 'Доступ LifeBalance',
      ],
      'redirectUrl' => 'https://example.test/return',
      'webHookUrl' => 'https://example.test/hook',
      'validity' => 86400,
      'paymentType' => 'debit',
    ], $body);
  }

  /**
   * A status body is mapped, and the masked card number is not kept.
   */
  public function testStatusNoticeDropsCardData(): void {
    $raw = '{"invoiceId":"p2_9ZgpZVsl3","status":"success","amount":4200,"ccy":980,"finalAmount":4200,"modifiedDate":"2019-08-24T14:15:22Z","reference":"84d0070ee4e44667b31371d8f8813947","paymentInfo":{"maskedPan":"444403******1902"}}';
    $gateway = $this->gateway([
      new Response(200, [], $raw),
    ]);
    $event = $gateway->getStatus('p2_9ZgpZVsl3');
    $this->assertNotNull($event);
    $this->assertSame(PaymentOrder::STATUS_PAID, $event->status);
    $this->assertSame('success', $event->providerStatus);
    $this->assertSame(4200, $event->amountMinor);
    $this->assertSame('UAH', $event->currency);
    $this->assertSame('84d0070ee4e44667b31371d8f8813947', $event->reference);
    $this->assertSame(1566656122, $event->modifiedAt);
    $encoded = json_encode(get_object_vars($event));
    $this->assertIsString($encoded);
    $this->assertStringNotContainsString('444403', $encoded);
    $this->assertStringNotContainsString(self::TOKEN, $encoded);
    $request = $this->recorded(0);
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('invoiceId=p2_9ZgpZVsl3', $request->getUri()->getQuery());
  }

  /**
   * Documented invoice statuses map in one place.
   */
  public function testStatusMap(): void {
    $gateway = $this->gateway([]);
    $this->assertSame(PaymentOrder::STATUS_PENDING, $gateway->mapStatus('created'));
    $this->assertSame(PaymentOrder::STATUS_PENDING, $gateway->mapStatus('processing'));
    $this->assertSame(PaymentOrder::STATUS_PENDING, $gateway->mapStatus('hold'));
    $this->assertSame(PaymentOrder::STATUS_PAID, $gateway->mapStatus('success'));
    $this->assertSame(PaymentOrder::STATUS_FAILED, $gateway->mapStatus('failure'));
    $this->assertSame(PaymentOrder::STATUS_EXPIRED, $gateway->mapStatus('expired'));
    $this->assertSame(PaymentOrder::STATUS_REFUNDED, $gateway->mapStatus('reversed'));
    $this->assertNull($gateway->mapStatus('nope'));
    $partial = $gateway->noticeFromWebhook('{"invoiceId":"p2_r","status":"reversed","amount":4200,"ccy":980,"finalAmount":100,"modifiedDate":"2019-08-24T14:15:22Z","reference":"ref-1"}');
    $this->assertNotNull($partial);
    $this->assertSame(PaymentOrder::STATUS_PARTIALLY_REFUNDED, $partial->status);
    $full = $gateway->noticeFromWebhook('{"invoiceId":"p2_r","status":"reversed","amount":4200,"ccy":980,"finalAmount":0,"modifiedDate":"2019-08-24T14:15:22Z","reference":"ref-1"}');
    $this->assertNotNull($full);
    $this->assertSame(PaymentOrder::STATUS_REFUNDED, $full->status);
    $foreign = $gateway->noticeFromWebhook('{"invoiceId":"p2_r","status":"success","amount":4200,"ccy":840,"modifiedDate":"2019-08-24T14:15:22Z","reference":"ref-1"}');
    $this->assertNotNull($foreign);
    $this->assertSame('840', $foreign->currency);
  }

  /**
   * A full refund posts only the invoice id.
   */
  public function testRefundPostsInvoiceOnly(): void {
    $gateway = $this->gateway([
      new Response(200, [], '{"status":"processing"}'),
    ]);
    $gateway->refund('p2_test');
    $body = json_decode((string) $this->recorded(0)->getBody(), TRUE);
    $this->assertSame(['invoiceId' => 'p2_test'], $body);
    $this->assertSame('/api/merchant/invoice/cancel', $this->recorded(0)->getUri()->getPath());
  }

  /**
   * A valid signature is checked with the cached key, then fetched once.
   */
  public function testSignatureUsesCachedKey(): void {
    $body = '{"invoiceId":"p2_test","status":"success"}';
    [$pem, $signature] = $this->sign($body);
    $gateway = $this->gateway([
      new Response(200, [], json_encode(base64_encode($pem))),
    ]);
    $this->assertTrue($gateway->verifyWebhook($body, $signature));
    $this->assertTrue($gateway->verifyWebhook($body, $signature));
    $this->assertCount(1, $this->history);
    $this->assertSame('/api/merchant/pubkey', $this->recorded(0)->getUri()->getPath());
  }

  /**
   * A bad signature fetches a fresh key once and still fails.
   */
  public function testInvalidSignatureRefreshesOnce(): void {
    $body = '{"invoiceId":"p2_test"}';
    [$pem] = $this->sign($body);
    $encoded = json_encode(base64_encode($pem));
    $gateway = $this->gateway([
      new Response(200, [], $encoded),
      new Response(200, [], $encoded),
    ]);
    $this->assertFalse($gateway->verifyWebhook($body, base64_encode('not-a-signature')));
    $this->assertFalse($gateway->verifyWebhook($body, base64_encode('still-not')));
    $this->assertCount(2, $this->history);
  }

  /**
   * An empty token never leaves the site.
   */
  public function testMissingTokenMakesNoRequest(): void {
    $gateway = new MonobankGateway(new Client(['handler' => HandlerStack::create(new MockHandler())]), $this->container->get(StateInterface::class), '');
    $this->assertFalse($gateway->hasToken());
    $this->assertNull($gateway->getStatus('p2_test'));
    $this->assertFalse($gateway->verifyWebhook('{}', 'abc'));
    try {
      $gateway->createInvoice(14900, 'UAH', 'ref', 'https://example.test/r', 'https://example.test/h');
      $this->fail('A missing token must stop the invoice.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Monobank token is not configured.', $exception->getMessage());
    }
  }

  /**
   * Transport errors do not keep the request in the message.
   */
  public function testTransportErrorHidesTheRequest(): void {
    $gateway = $this->gateway([
      static function (): void {
        throw new \RuntimeException('X-Token ' . self::TOKEN);
      },
    ]);
    try {
      $gateway->createInvoice(14900, 'UAH', 'ref', 'https://example.test/r', 'https://example.test/h');
      $this->fail('A transport error must be replaced.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Monobank request failed.', $exception->getMessage());
      $this->assertNull($exception->getPrevious());
      $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
    }
  }

  /**
   * Request captured by the fake HTTP client.
   */
  private function recorded(int $index): RequestInterface {
    $entry = $this->history[$index];
    $request = $entry['request'] ?? NULL;
    $this->assertInstanceOf(RequestInterface::class, $request);
    return $request;
  }

  /**
   * Gateway with a queue of fake responses.
   *
   * @param array<int, mixed> $responses
   *   Mock handler queue.
   */
  private function gateway(array $responses): MonobankGateway {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $client = new Client(['handler' => $stack, 'http_errors' => FALSE]);
    return new MonobankGateway($client, $this->container->get(StateInterface::class), self::TOKEN);
  }

  /**
   * Signs a body the way the provider does.
   *
   * @return array{0: string, 1: string}
   *   PEM public key and base64 signature.
   */
  private function sign(string $body): array {
    $key = openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_EC,
      'curve_name' => 'prime256v1',
    ]);
    $this->assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    $this->assertIsArray($details);
    $pem = $details['key'];
    $this->assertIsString($pem);
    $raw = '';
    $this->assertTrue(openssl_sign($body, $raw, $key, OPENSSL_ALGO_SHA256));
    return [$pem, base64_encode($raw)];
  }

}
