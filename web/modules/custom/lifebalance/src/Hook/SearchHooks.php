<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Seo\PublicMeta;
use Drupal\lifebalance\Seo\VisitStats;

/**
 * Search tags for public pages. Other pages stay out of the index.
 */
final class SearchHooks {

  public function __construct(
    private readonly PublicMeta $publicMeta,
    private readonly VisitStats $visitStats,
  ) {}

  /**
   * Adds language alternates, or a noindex directive.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $this->publicMeta->attach($attachments);
    $this->visitStats->attach($attachments);
  }

}
