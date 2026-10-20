<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance\Seo\PublicMeta;

/**
 * Lists the public pages for search engines.
 */
final class SitemapController extends ControllerBase {

  public function __construct(
    private readonly PublicMeta $publicMeta,
  ) {}

  /**
   * The public URL set. Personal routes are absent.
   */
  public function build(): CacheableResponse {
    return $this->publicMeta->sitemap();
  }

}
