<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Service;

/**
 * A payment provider. Card data never reaches this site.
 *
 * Status names of a real provider are mapped only inside that provider's
 * class. Callers see the order statuses from PaymentOrder.
 */
interface PaymentGatewayInterface {

  /**
   * Provider id stored on the order.
   */
  public function id(): string;

  /**
   * Opens a hosted payment page for one order.
   *
   * @param int $amountMinor
   *   Amount in minor units, taken from the order.
   * @param string $currency
   *   Currency taken from the order.
   * @param string $reference
   *   Order uuid.
   * @param string $redirectUrl
   *   Where the provider sends the browser afterwards.
   * @param string $webhookUrl
   *   Where the provider posts signed notices.
   * @param int $validity
   *   Invoice lifetime in seconds.
   */
  public function createInvoice(int $amountMinor, string $currency, string $reference, string $redirectUrl, string $webhookUrl, int $validity = 86400): InvoiceResult;

  /**
   * Authoritative status from the provider, or NULL when unknown.
   */
  public function getStatus(string $invoiceId): ?ProviderEvent;

  /**
   * Asks the provider to refund the whole invoice.
   */
  public function refund(string $invoiceId): void;

  /**
   * Whether the signature matches the raw body.
   */
  public function verifyWebhook(string $rawBody, string $signature): bool;

  /**
   * Header that carries the signature of the raw body.
   */
  public function signatureHeader(): string;

  /**
   * Reads a signed notice, or NULL when the body is not one we accept.
   */
  public function noticeFromWebhook(string $rawBody): ?ProviderEvent;

  /**
   * Maps a provider status onto an order status, or NULL when unknown.
   */
  public function mapStatus(string $providerStatus): ?string;

}
