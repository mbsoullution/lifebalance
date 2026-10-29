<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Theme hooks for the wheel page.
 */
final class ThemeHooks {

  /**
   * Registers the radar template.
   *
   * @param mixed $existing
   *   Existing implementations.
   * @param mixed $type
   *   The theme engine type.
   * @param mixed $theme
   *   The theme name.
   * @param mixed $path
   *   The theme path.
   *
   * @return array<string, mixed>
   *   Theme hook definitions.
   */
  #[Hook('theme')]
  public function theme($existing, $type, $theme, $path): array {
    return [
      'lifebalance_radar' => [
        'variables' => [
          'geometry' => [
            'axes' => [],
            'rings' => [],
            'wedges' => [],
            'labels' => [],
            'points' => '',
          ],
        ],
        'template' => 'lifebalance-radar',
      ],
      'lifebalance_language_switcher' => [
        'variables' => [
          'options' => [],
          'action' => '/',
        ],
        'template' => 'lifebalance-language-switcher',
      ],
      'lifebalance_login_icons' => [
        'variables' => [
          'google_url' => NULL,
          'facebook_url' => NULL,
        ],
        'template' => 'lifebalance-login-icons',
      ],
      'lifebalance_trend' => [
        'variables' => [
          'geometry' => [
            'width' => 320,
            'height' => 180,
            'axis_top' => '20.00',
            'axis_bottom' => '164.00',
            'dots' => [],
            'line' => '',
          ],
        ],
        'template' => 'lifebalance-trend',
      ],
    ];
  }

}
