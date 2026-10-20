<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

use Drupal\Core\State\StateInterface;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_billing\Entity\PaymentOrder;

/**
 * Test provider with its own ECDSA key pair.
 *
 * The private key stays in state. It is not configuration. Status strings
 * here are this double's own names, already equal to order statuses.
 */
final class FakeGateway implements PaymentGatewayInterface {

  /**
   * Header carrying the base64 ECDSA signature of the raw body.
   */
  public const SIGNATURE_HEADER = 'X-LifeBalance-Signature';

  private const KEY_STATE = 'lifebalance_billing.fake_keys';

  private const INVOICE_STATE = 'lifebalance_billing.fake_invoices';

  public function __construct(
    private readonly StateInterface $state,
    private readonly Clock $clock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'fake';
  }

  /**
   * {@inheritdoc}
   */
  public function createInvoice(int $amountMinor, string $currency, string $reference, string $redirectUrl, string $webhookUrl, int $validity = 86400): InvoiceResult {
    $invoice_id = 'fake_' . $reference;
    $invoices = $this->invoices();
    $invoices[$invoice_id] = [
      'amount' => $amountMinor,
      'currency' => $currency,
      'reference' => $reference,
      'status' => PaymentOrder::STATUS_PENDING,
      'modified' => $this->clock->now(),
      'webhook' => $webhookUrl,
      'validity' => $validity,
    ];
    $this->state->set(self::INVOICE_STATE, $invoices);
    return new InvoiceResult($invoice_id, $redirectUrl);
  }

  /**
   * Invoice lifetime stored for this invoice.
   *
   * @param string $invoiceId
   *   The provider invoice id.
   */
  public function validityOf(string $invoiceId): int {
    $validity = $this->invoices()[$invoiceId]['validity'] ?? 0;
    return is_int($validity) ? $validity : 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(string $invoiceId): ?ProviderEvent {
    $invoice = $this->invoices()[$invoiceId] ?? NULL;
    if (!is_array($invoice)) {
      return NULL;
    }
    $status = $invoice['status'] ?? '';
    if (!is_string($status)) {
      return NULL;
    }
    $mapped = $this->mapStatus($status);
    if ($mapped === NULL) {
      return NULL;
    }
    $amount = $invoice['amount'] ?? 0;
    $modified = $invoice['modified'] ?? 0;
    $currency = $invoice['currency'] ?? '';
    $reference = $invoice['reference'] ?? '';
    if (!is_int($amount) || !is_int($modified) || !is_string($currency) || !is_string($reference)) {
      return NULL;
    }
    $canonical = json_encode([
      'amount' => $amount,
      'currency' => $currency,
      'invoiceId' => $invoiceId,
      'modifiedDate' => $modified,
      'reference' => $reference,
      'status' => $status,
    ]);
    if (!is_string($canonical)) {
      return NULL;
    }
    return new ProviderEvent(
      $invoiceId,
      $mapped,
      $status,
      $amount,
      $currency,
      $reference,
      $modified,
      hash('sha256', $canonical),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function refund(string $invoiceId): void {
    $current = $this->invoices()[$invoiceId]['modified'] ?? 0;
    $modified = $this->clock->now();
    if (is_int($current) && $current >= $modified) {
      $modified = $current + 1;
    }
    $this->setRemoteStatus($invoiceId, PaymentOrder::STATUS_REFUNDED, $modified);
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
  public function noticeFromWebhook(string $rawBody): ?ProviderEvent {
    try {
      $data = json_decode($rawBody, TRUE, 8, JSON_THROW_ON_ERROR);
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
    $currency = $data['currency'] ?? NULL;
    $reference = $data['reference'] ?? NULL;
    $modified = $data['modifiedDate'] ?? NULL;
    if (!is_string($invoice) || $invoice === '' || strlen($invoice) > 64
      || !is_string($status) || $status === '' || strlen($status) > 64
      || !is_int($amount) || $amount < 0
      || !is_string($currency) || strlen($currency) !== 3
      || !is_string($reference) || !preg_match('/^[0-9a-fA-F-]{1,128}$/', $reference)
      || !is_int($modified) || $modified < 1
    ) {
      return NULL;
    }
    $mapped = $this->mapStatus($status);
    if ($mapped === NULL) {
      return NULL;
    }
    return new ProviderEvent(
      $invoice,
      $mapped,
      $status,
      $amount,
      $currency,
      $reference,
      $modified,
      hash('sha256', $rawBody),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function verifyWebhook(string $rawBody, string $signature): bool {
    $raw = base64_decode($signature, TRUE);
    $public = $this->keys()['public'];
    if ($raw === FALSE || $raw === '' || $public === '') {
      return FALSE;
    }
    $verified = openssl_verify($rawBody, $raw, $public, OPENSSL_ALGO_SHA256);
    return $verified === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function mapStatus(string $providerStatus): ?string {
    $known = [
      PaymentOrder::STATUS_PENDING,
      PaymentOrder::STATUS_PAID,
      PaymentOrder::STATUS_FAILED,
      PaymentOrder::STATUS_CANCELLED,
      PaymentOrder::STATUS_EXPIRED,
      PaymentOrder::STATUS_REFUNDED,
      PaymentOrder::STATUS_PARTIALLY_REFUNDED,
      PaymentOrder::STATUS_CHARGEBACK,
    ];
    return in_array($providerStatus, $known, TRUE) ? $providerStatus : NULL;
  }

  /**
   * Signs a raw body with the test private key.
   */
  public function sign(string $rawBody): string {
    $private = $this->keys()['private'];
    if ($private === '') {
      throw new \RuntimeException('The test billing key is missing.');
    }
    $signature = '';
    $signed = openssl_sign($rawBody, $signature, $private, OPENSSL_ALGO_SHA256);
    if ($signed !== TRUE) {
      throw new \RuntimeException('The test billing key could not sign.');
    }
    return base64_encode($signature);
  }

  /**
   * Sets the status a later getStatus() or lost-webhook check will see.
   */
  public function setRemoteStatus(string $invoiceId, string $providerStatus, int $modifiedAt): void {
    if ($this->mapStatus($providerStatus) === NULL) {
      throw new \InvalidArgumentException('Unknown test provider status.');
    }
    $invoices = $this->invoices();
    $current = $invoices[$invoiceId] ?? NULL;
    if (!is_array($current)) {
      throw new \InvalidArgumentException('Unknown test invoice.');
    }
    $current['status'] = $providerStatus;
    $current['modified'] = $modifiedAt;
    $invoices[$invoiceId] = $current;
    $this->state->set(self::INVOICE_STATE, $invoices);
  }

  /**
   * Loads or creates the ECDSA pair used only by this double.
   *
   * @return array{public: string, private: string}
   *   PEM strings.
   */
  private function keys(): array {
    $stored = $this->state->get(self::KEY_STATE);
    if (is_array($stored) && is_string($stored['public'] ?? NULL) && is_string($stored['private'] ?? NULL)) {
      return [
        'public' => $stored['public'],
        'private' => $stored['private'],
      ];
    }
    $key = openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_EC,
      'curve_name' => 'prime256v1',
    ]);
    if ($key === FALSE) {
      throw new \RuntimeException('The test billing key could not be created.');
    }
    $private = '';
    if (openssl_pkey_export($key, $private) !== TRUE) {
      throw new \RuntimeException('The test billing key could not be exported.');
    }
    $details = openssl_pkey_get_details($key);
    $public = is_array($details) ? ($details['key'] ?? '') : '';
    if (!is_string($public) || $public === '' || $private === '') {
      throw new \RuntimeException('The test billing key could not be exported.');
    }
    $this->state->set(self::KEY_STATE, [
      'public' => $public,
      'private' => $private,
    ]);
    return ['public' => $public, 'private' => $private];
  }

  /**
   * Stored fake invoices.
   *
   * @return array<string, array<string, int|string>>
   *   Invoices keyed by id.
   */
  private function invoices(): array {
    $stored = $this->state->get(self::INVOICE_STATE, []);
    return is_array($stored) ? $stored : [];
  }

}
