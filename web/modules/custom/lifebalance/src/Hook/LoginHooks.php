<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\lifebalance\Service\LoginEntry;

/**
 * Shapes the password form used in the header and on the login page.
 */
final class LoginHooks {

  public function __construct(
    private readonly LoginEntry $loginEntry,
  ) {}

  /**
   * Centers the login form. The login page also shows the icon row.
   *
   * @param array<string, mixed> $form
   *   The user login form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state. The shared login layout does not read it.
   * @param string $form_id
   *   The form id.
   */
  #[Hook('form_user_login_form_alter')]
  public function alterLogin(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    if ($form_id === 'user_login_form') {
      $show_dialog = $form_state->isSubmitted() && $form_state->hasAnyErrors();
      $form = $this->loginEntry->prepareForm($form, $show_dialog);
    }
  }

}
