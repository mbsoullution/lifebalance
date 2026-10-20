<?php

declare(strict_types=1);

namespace Drupal\lifebalance\SocialAuth;

use Drupal\Core\Url;
use Drupal\social_auth\User\UserAuthenticator as SocialAuthUserAuthenticator;
use Drupal\social_auth\User\UserManager as SocialAuthUserManager;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Stops Social Auth from logging into an account that only shares an email.
 *
 * A person who is already logged in can still attach a provider. That path
 * does not call this method.
 */
final class UserAuthenticator extends SocialAuthUserAuthenticator {

  /**
   * {@inheritdoc}
   */
  protected function isRegistrationDisabled(): bool {
    return $this->configFactory->get('social_auth.settings')->get('user_allowed') === 'login';
  }

  /**
   * Refuses to sign in when the email already belongs to an account.
   *
   * @param string $email
   *   Email returned by the provider.
   * @param string $provider_user_id
   *   Provider account id. A match must not store it.
   * @param string $token
   *   Provider token. A match must not store it.
   * @param array<string, mixed>|null $data
   *   Extra provider data. A match must not store it.
   *
   * @return bool
   *   TRUE when an existing account blocks the sign-in.
   */
  public function authenticateWithEmail(string $email, string $provider_user_id, string $token, ?array $data): bool {
    unset($provider_user_id, $token, $data);
    try {
      $manager = $this->userManager;
      if (!$manager instanceof SocialAuthUserManager) {
        return $this->denyEmailLink();
      }
      $existing = $manager->loadUserByProperty('mail', $email);
    }
    catch (\Exception) {
      $this->loggerFactory->get('lifebalance')->error('Social auth email lookup failed.');
      return $this->denyEmailLink();
    }
    if ($existing) {
      return $this->denyEmailLink();
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticateNewUser(?UserInterface $drupal_user = NULL): void {
    if ($drupal_user instanceof UserInterface && ($drupal_user->getEmail() ?? '') === '') {
      $this->messenger->addWarning($this->t('Провайдер не передав email. Без email відновлення пароля недоступне.'));
    }
    parent::authenticateNewUser($drupal_user);
  }

  /**
   * Sends the visitor to the explanation page without writing a provider.
   *
   * @return bool
   *   TRUE so the caller returns this redirect and does not create an account.
   */
  private function denyEmailLink(): bool {
    $this->nullifySessionKeys();
    $this->response = new RedirectResponse(Url::fromRoute('lifebalance.social_email_conflict')->toString());
    return TRUE;
  }

}
