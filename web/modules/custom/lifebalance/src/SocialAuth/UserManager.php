<?php

declare(strict_types=1);

namespace Drupal\lifebalance\SocialAuth;

use Drupal\social_auth\User\UserManager as SocialAuthUserManager;

/**
 * Lets a social provider create an active account.
 *
 * Password registration stays admin-only. Social Auth treats that setting as
 * "do not register", which would also block the first Google or Facebook
 * sign-in.
 */
final class UserManager extends SocialAuthUserManager {

  /**
   * {@inheritdoc}
   */
  protected function isRegistrationDisabled(): bool {
    return $this->configFactory->get('social_auth.settings')->get('user_allowed') === 'login';
  }

  /**
   * {@inheritdoc}
   */
  protected function getNewUserStatus(): int {
    return 1;
  }

}
