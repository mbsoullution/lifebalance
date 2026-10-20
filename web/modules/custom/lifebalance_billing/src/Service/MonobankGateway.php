<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\State\StateInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Monobank acquiring. Field names here follow the merchant API.
 *
 * The merchant token is passed in and is never written to config or logs.
 * Invoice status values are created, processing, hold, success, failure,
 * reversed, and expired. The signature header is X-Sign.
 */
final class MonobankGateway implements PaymentGatewayInterface {

  public const SIGNATURE_HEADER = 'X-Sign';

  private const ENDPOINT = 'https://api.monobank.ua';

  private const KEY_STATE = 'lifebalance_billing.monobank_pubkey';

  /**
   * ISO 4217 numeric code for the hryvnia.
   */
  private const UAH = 980;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly string $token,
  ) {}

  /**
   * Whether a merchant token was provided.
   */
  public function hasToken(): bool {
    return $this->token !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'monobank';
  }

  /**
   * {@inheritdoc}
   */
  public function signatureHeader(): string {
    return self::SIGNATURE_HEADER;
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(int $amountMinor, string $currency, string $reference, string $redirectUrl, string $webhookUrl, int $validity = 86400): InvoiceResult {
    $this->assertToken();
    if ($currency !== 'UAH') {
      throw new \InvalidArgumentException('This provider accepts UAH only.');
    }
    $payload = $this->request('POST', '/api/merchant/invoice/create', [
      'amount' => $amountMinor,
      'ccy' => self::UAH,
      'merchantPaymInfo' => [
        'reference' => $reference,
        'destination' => 'Доступ LifeBalance',
      ],
      'redirectUrl' => $redirectUrl,
      'webHookUrl' => $webhookUrl,
      'validity' => $validity,
      'paymentType' => 'debit',
    ]);
    $invoice_id = $payload['invoiceId'] ?? NULL;
    $page_url = $payload['pageUrl'] ?? NULL;
    if (!is_string($invoice_id) || $invoice_id === '' || !is_string($page_url) || $page_url === '') {
      throw new \RuntimeException('Monobank invoice request failed.');
    }
    return new InvoiceResult($invoice_id, $page_url);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(string $invoiceId): ?ProviderEvent {
    if ($this->token === '' || $invoiceId === '') {
      return NULL;
    }
    try {
      $response = $this->send('GET', '/api/merchant/invoice/status?invoiceId=' . rawurlencode($invoiceId));
    }
    catch (\RuntimeException) {
      return NULL;
    }
    if ($response->getStatusCode() !== 200) {
      return NULL;
    }
    return $this->noticeFromWebhook((string) $response->getBody());
  }

  /**
   * {@inheritdoc}
   */
  public function refund(string $invoiceId): void {
    $this->assertToken();
    $payload = $this->request('POST', '/api/merchant/invoice/cancel', [
      'invoiceId' => $invoiceId,
    ]);
    $status = $payload['status'] ?? NULL;
    if (!is_string($status) || !in_array($status, ['processing', 'success'], TRUE)) {
      throw new \RuntimeException('Monobank refund request failed.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verifyWebhook(string $rawBody, string $signature): bool {
    if ($this->token === '' || $signature === '') {
      return FALSE;
    }
    $cached = $this->state->get(self::KEY_STATE);
    if (is_string($cached) && $cached !== '' && $this->verifyWith($rawBody, $signature, $cached)) {
      return TRUE;
    }
    $fresh = $this->fetchKey();
    if ($fresh === NULL) {
      return FALSE;
    }
    $this->state->set(self::KEY_STATE, $fresh);
    return $this->verifyWith($rawBody, $signature, $fresh);
  }

  /**
   * {@inheritdoc}
   */
  public function noticeFromWebhook(string $rawBody): ?ProviderEvent {
    try {
      $data = json_decode($rawBody, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($data)) {
      return NULL;
    }
    $invoice = $data['invoiceId'] ?? NULL;
    $status = $data['status'] ?? NULL;
    $amount = $data['amount'] ?? NULL;
    $ccy = $data['ccy'] ?? NULL;
    $reference = $data['reference'] ?? NULL;
    $modified = $data['modifiedDate'] ?? NULL;
    if (!is_string($invoice) || $invoice === '' || strlen($invoice) > 64
      || !is_string($status) || $status === ''
      || !is_int($amount) || $amount < 0
      || !is_int($ccy)
      || !is_string($reference) || $reference === ''
      || !is_string($modified)
    ) {
      return NULL;
    }
    $mapped = $this->mapInvoice($status, $data['finalAmount'] ?? NULL);
    $instant = $this->modifiedAt($modified);
    if ($mapped === NULL || $instant === NULL) {
      return NULL;
    }
    return new ProviderEvent(
      $invoice,
      $mapped,
      $status,
      $amount,
      $ccy === self::UAH ? 'UAH' : (string) $ccy,
      $reference,
      $instant,
      hash('sha256', $rawBody),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function mapStatus(string $providerStatus): ?string {
    return $this->mapInvoice($providerStatus, NULL);
  }

  /**
   * Maps an invoice status. A reversed invoice with money left is partial.
   */
  private function mapInvoice(string $providerStatus, mixed $finalAmount): ?string {
    if ($providerStatus === 'reversed') {
      if (is_int($finalAmount) && $finalAmount > 0) {
        return PaymentOrder::STATUS_PARTIALLY_REFUNDED;
      }
      return PaymentOrder::STATUS_REFUNDED;
    }
    return match ($providerStatus) {
      'created', 'processing', 'hold' => PaymentOrder::STATUS_PENDING,
      'success' => PaymentOrder::STATUS_PAID,
      'failure' => PaymentOrder::STATUS_FAILED,
      'expired' => PaymentOrder::STATUS_EXPIRED,
      default => NULL,
    };
  }

  /**
   * Unix time from an ISO-8601 provider timestamp, or NULL.
   */
  private function modifiedAt(string $value): ?int {
    if (!str_contains($value, 'T')) {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($value))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Fetches the current ECDSA key once. The key is not logged.
   */
  private function fetchKey(): ?string {
    try {
      $response = $this->send('GET', '/api/merchant/pubkey');
    }
    catch (\RuntimeException) {
      return NULL;
    }
    if ($response->getStatusCode() !== 200) {
      return NULL;
    }
    $raw = (string) $response->getBody();
    $decoded = json_decode($raw);
    $body = is_string($decoded) ? $decoded : trim($raw);
    if ($body === '') {
      return NULL;
    }
    $pem = base64_decode($body, TRUE);
    if ($pem === FALSE || !str_contains($pem, 'PUBLIC KEY')) {
      return NULL;
    }
    return $pem;
  }

  /**
   * Checks one signature against one PEM key.
   */
  private function verifyWith(string $rawBody, string $signature, string $pem): bool {
    $raw = base64_decode($signature, TRUE);
    $public = openssl_pkey_get_public($pem);
    if ($raw === FALSE || $raw === '' || $public === FALSE) {
      return FALSE;
    }
    return openssl_verify($rawBody, $raw, $public, OPENSSL_ALGO_SHA256) === 1;
  }

  /**
   * Calls the API and returns a JSON object.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path beginning with a slash.
   * @param array<string, mixed>|null $json
   *   Request body, or NULL for a request without one.
   *
   * @return array<string, mixed>
   *   Decoded object.
   */
  private function request(string $method, string $path, ?array $json = NULL): array {
    $response = $this->send($method, $path, $json);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Monobank request failed.');
    }
    try {
      $decoded = json_decode((string) $response->getBody(), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \RuntimeException('Monobank request failed.');
    }
    if (!is_array($decoded)) {
      throw new \RuntimeException('Monobank request failed.');
    }
    return $decoded;
  }

  /**
   * Sends one request. Connection errors do not keep the token in the message.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path beginning with a slash.
   * @param array<string, mixed>|null $json
   *   Request body, or NULL for a request without one.
   */
  private function send(string $method, string $path, ?array $json = NULL): ResponseInterface {
    $this->assertToken();
    $options = [
      'headers' => [
        'X-Token' => $this->token,
        'Accept' => 'application/json',
      ],
      'http_errors' => FALSE,
    ];
    if ($json !== NULL) {
      $encoded = json_encode($json);
      if (!is_string($encoded)) {
        throw new \RuntimeException('Monobank request failed.');
      }
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = $encoded;
    }
    try {
      return $this->httpClient->request($method, self::ENDPOINT . $path, $options);
    }
    catch (\Throwable) {
      throw new \RuntimeException('Monobank request failed.');
    }
  }

  /**
   * Refuses a call that would otherwise send an empty token.
   */
  private function assertToken(): void {
    if ($this->token === '') {
      throw new \RuntimeException('Monobank token is not configured.');
    }
  }

}
