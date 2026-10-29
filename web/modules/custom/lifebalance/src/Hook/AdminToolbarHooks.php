<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Moves the Gin admin sidebar to the right for toolbar users.
 */
final class AdminToolbarHooks {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Attaches the right-side sidebar styles.
   *
   * @param array<string, mixed> $attachments
   *   The page attachments.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if ($this->currentUser->hasPermission('access toolbar')) {
      $attachments['#attached']['library'][] = 'lifebalance/admin_toolbar_side';
    }
  }

}
