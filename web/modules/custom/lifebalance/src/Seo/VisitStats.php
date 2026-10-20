<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Seo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Google Analytics 4 on public pages only.
 *
 * Scores, answers, notes, and personal addresses are not sent. The tag stays
 * off until a measurement id is saved.
 */
final class VisitStats {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly PublicMeta $publicMeta,
    private readonly ModuleExtensionList $modules,
  ) {}

  /**
   * Adds the Google tag when this route is public and an id is saved.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   */
  public function attach(array &$attachments): void {
    $attachments['#cache']['tags'][] = 'config:lifebalance.settings';
    $id = $this->measurementId();
    if ($id === NULL) {
      return;
    }
    $route = $this->routeMatch->getRouteName();
    if (!$this->publicMeta->isPublicRoute(is_string($route) ? $route : NULL)) {
      return;
    }
    $gtag = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($id);
    $self = Url::fromUri('base:' . $this->modules->getPath('lifebalance') . '/js/ga.js')->toString();
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'src' => $gtag,
          'async' => TRUE,
        ],
      ],
      'lifebalance_gtag',
    ];
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'src' => $self,
          'data-measurement-id' => $id,
        ],
      ],
      'lifebalance_gtag_config',
    ];
  }

  /**
   * Saved measurement id, or NULL when the counter is off.
   */
  private function measurementId(): ?string {
    $id = $this->configFactory->get('lifebalance.settings')->get('ga_measurement_id');
    if (!is_string($id)) {
      return NULL;
    }
    $id = strtoupper(trim($id));
    if (preg_match('/^G-[A-Z0-9]{4,32}$/', $id) !== 1) {
      return NULL;
    }
    return $id;
  }

}
