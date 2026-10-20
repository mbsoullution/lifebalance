<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Country of the current visitor, from a local address table.
 *
 * Ukraine and Russia are the only countries the table names. Any other
 * public address is reported as XX. A private or local address has no
 * country. Tests pin a country with STATE_KEY.
 */
final class VisitorCountry {

  /**
   * State key for a test country. Production never sets it.
   */
  public const STATE_KEY = 'lifebalance.visitor_country';

  /**
   * Marker for a public address that is neither Ukraine nor Russia.
   */
  public const OTHER = 'XX';

  /**
   * Address table, loaded on the first lookup.
   */
  private ?CountryRangeTable $table = NULL;

  public function __construct(
    private readonly StateInterface $state,
    private readonly RequestStack $requestStack,
    private readonly ModuleExtensionList $modules,
    private readonly string $appRoot,
  ) {}

  /**
   * UA, RU, XX, or NULL when the address does not identify a country.
   */
  public function code(?Request $request = NULL): ?string {
    $override = $this->state->get(self::STATE_KEY);
    if (is_string($override) && preg_match('/^[A-Z]{2}$/', $override) === 1) {
      return $override;
    }
    if (drupal_valid_test_ua()) {
      return NULL;
    }
    $request = $request ?? $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $ip = $request->getClientIp();
    if (!is_string($ip) || $this->isLocal($ip)) {
      return NULL;
    }
    return $this->ranges()->lookup($ip) ?? self::OTHER;
  }

  /**
   * Private, loopback, and reserved addresses are not a country.
   */
  private function isLocal(string $ip): bool {
    $packed = @inet_pton($ip);
    if ($packed === FALSE) {
      return TRUE;
    }
    $mapped = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
    if (strlen($packed) === 16 && str_starts_with($packed, $mapped)) {
      $ip = inet_ntop(substr($packed, 12)) ?: $ip;
    }
    return filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === FALSE;
  }

  /**
   * Address table shipped with the module.
   */
  private function ranges(): CountryRangeTable {
    if ($this->table === NULL) {
      $directory = $this->appRoot . '/' . $this->modules->getPath('lifebalance') . '/data';
      $this->table = new CountryRangeTable($directory);
    }
    return $this->table;
  }

}
