<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Reads catalog_version from the shipped criteria file.
 */
final class CatalogVersion {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly string $appRoot,
  ) {}

  /**
   * Positive catalog version from criteria.v1.yml.
   */
  public function current(): int {
    $path = $this->appRoot . '/' . $this->moduleExtensionList->getPath('lifebalance') . '/data/criteria.v1.yml';
    $contents = is_file($path) ? file_get_contents($path) : FALSE;
    if ($contents === FALSE) {
      throw new \LogicException('Criteria catalog file could not be read.');
    }
    $decoded = Yaml::decode($contents);
    $version = is_array($decoded) ? ($decoded['catalog_version'] ?? NULL) : NULL;
    if (!is_int($version) || $version < 1) {
      throw new \LogicException('Criteria catalog_version must be a positive integer.');
    }
    return $version;
  }

}
