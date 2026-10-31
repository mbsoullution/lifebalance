<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Seo\PreviewLock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Replaces index directives on the preview hostname.
 */
final class PreviewHooks {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Forces noindex on preview HTML, including pages that are public elsewhere.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $request = $this->requestStack->getCurrentRequest();
    if (!PreviewLock::isPreviewHost($request?->getHost())) {
      return;
    }
    $attachments['#cache']['contexts'][] = 'headers:host';
    $heads = $attachments['#attached']['html_head'] ?? [];
    if (!is_array($heads)) {
      $heads = [];
    }
    $found = FALSE;
    foreach ($heads as $index => $item) {
      if (!is_array($item) || !isset($item[0]) || !is_array($item[0])) {
        continue;
      }
      $tag = $item[0];
      $name = $tag['#attributes']['name'] ?? NULL;
      if (($tag['#tag'] ?? NULL) !== 'meta' || $name !== 'robots') {
        continue;
      }
      $content = 'noindex, nofollow, noarchive';
      $heads[$index][0]['#attributes']['content'] = $content;
      $found = TRUE;
    }
    if (!$found) {
      $heads[] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'robots',
            'content' => 'noindex, nofollow, noarchive',
          ],
        ],
        'lifebalance_preview_robots',
      ];
    }
    $attachments['#attached']['html_head'] = $heads;
  }

}
