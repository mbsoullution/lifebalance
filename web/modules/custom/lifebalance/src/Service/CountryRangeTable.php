<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

/**
 * Looks up Ukraine and Russia in a local address table.
 *
 * The tables are the public-domain user-country lists from ip-location-db,
 * filtered to UA and RU. Each record is a start address, an end address, and
 * the two-letter country, sorted by start. Addresses are network byte order.
 */
final class CountryRangeTable {

  public function __construct(
    private readonly string $directory,
  ) {}

  /**
   * Country code for a public address, or NULL outside Ukraine and Russia.
   */
  public function lookup(string $ip): ?string {
    $packed = $this->packed($ip);
    if ($packed === NULL) {
      return NULL;
    }
    $width = strlen($packed);
    $name = $width === 4 ? 'country-ua-ru-ipv4.bin' : 'country-ua-ru-ipv6.bin';
    $blob = $this->read($name);
    $record = ($width * 2) + 2;
    if ($blob === '' || strlen($blob) % $record !== 0) {
      return NULL;
    }
    $low = 0;
    $high = intdiv(strlen($blob), $record) - 1;
    while ($low <= $high) {
      $middle = intdiv($low + $high, 2);
      $offset = $middle * $record;
      $start = substr($blob, $offset, $width);
      $end = substr($blob, $offset + $width, $width);
      if (strcmp($packed, $start) < 0) {
        $high = $middle - 1;
        continue;
      }
      if (strcmp($packed, $end) > 0) {
        $low = $middle + 1;
        continue;
      }
      $country = substr($blob, $offset + ($width * 2), 2);
      return $country === 'UA' || $country === 'RU' ? $country : NULL;
    }
    return NULL;
  }

  /**
   * Network-order address, with IPv4-mapped IPv6 reduced to IPv4.
   */
  private function packed(string $ip): ?string {
    $packed = @inet_pton($ip);
    if ($packed === FALSE) {
      return NULL;
    }
    $mapped = "\0\0\0\0\0\0\0\0\0\0\xff\xff";
    if (strlen($packed) === 16 && str_starts_with($packed, $mapped)) {
      return substr($packed, 12);
    }
    return $packed;
  }

  /**
   * Reads one table, or an empty string when the file is missing.
   */
  private function read(string $name): string {
    $path = $this->directory . '/' . $name;
    if (!is_file($path)) {
      return '';
    }
    $blob = file_get_contents($path);
    return is_string($blob) ? $blob : '';
  }

}
