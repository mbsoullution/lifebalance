<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Explains that a matching email does not sign the person into that account.
 */
final class SocialEmailConflictController extends ControllerBase {

  /**
   * The explanation shown after a provider email matches another account.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    return [
      'lead' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t(
          "Увійдіть наявним способом і прив'яжіть його в налаштуваннях.",
        ),
      ],
      'login' => [
        '#type' => 'link',
        '#title' => $this->t('Увійти наявним способом'),
        '#url' => Url::fromRoute('user.login'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
