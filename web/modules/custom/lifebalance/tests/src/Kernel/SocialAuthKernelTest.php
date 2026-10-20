<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\SocialAuth\ProviderLinks;
use Drupal\lifebalance\SocialAuth\UserAuthenticator;
use Drupal\lifebalance\SocialAuth\UserManager;
use Drupal\social_auth\Entity\SocialAuth;
use Drupal\social_auth\User\SocialAuthUser;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Social sign-in does not take over an account that only shares an email.
 */
#[Group('lifebalance')]
final class SocialAuthKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'social_api',
    'social_auth',
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('social_auth');
    $this->installConfig(['social_auth']);
    $this->config('user.settings')->set('register', 'admin_only')->save();
    $this->config('social_auth.settings')->set('post_login', '/wheel')->save();

    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    // User 1 is refused by Social Auth when admin login is disabled.
    User::create([
      'name' => 'site-admin',
      'mail' => 'site-admin@example.com',
      'status' => 1,
    ])->save();
    $this->container->get('social_auth.data_handler')->setSessionPrefix('social_auth');
  }

  /**
   * A matching email redirects and writes nothing.
   */
  public function testEmailMatchDoesNotLinkOrLogin(): void {
    $owner = User::create([
      'name' => 'owner',
      'mail' => 'owner@example.com',
      'status' => 1,
    ]);
    $owner->save();
    $this->setCurrentUser(new AnonymousUserSession());

    $authenticator = $this->authenticator();
    $authenticator->setPluginId('social_auth_facebook');
    $response = $authenticator->authenticateUser(new SocialAuthUser(
      'Other',
      'facebook-1',
      'token',
      'owner@example.com',
    ));

    $this->assertStringContainsString('/login/email-match', $response->getTargetUrl());
    $this->assertSame(0, (int) $this->container->get('current_user')->id());
    $this->assertSame([], $this->socialAuthIds());
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties([
      'mail' => 'owner@example.com',
    ]);
    $this->assertCount(1, $accounts);
  }

  /**
   * A new social account is active while password registration stays closed.
   */
  public function testSocialRegistrationStaysOpen(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $authenticator = $this->authenticator();
    $authenticator->setPluginId('social_auth_google');
    $response = $authenticator->authenticateUser(new SocialAuthUser(
      'Nova',
      'google-1',
      'token',
      'nova@example.com',
    ));

    $this->assertStringContainsString('/wheel', $response->getTargetUrl());
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties([
      'mail' => 'nova@example.com',
    ]);
    $this->assertCount(1, $accounts);
    $created = reset($accounts);
    $this->assertTrue($created->isActive());
    $this->assertSame((int) $created->id(), (int) $this->container->get('current_user')->id());
    $this->assertCount(1, $this->socialAuthIds());
  }

  /**
   * A known provider id signs into that same account.
   */
  public function testKnownProviderLogsIntoTheSameAccount(): void {
    $owner = User::create([
      'name' => 'owner',
      'mail' => 'owner@example.com',
      'status' => 1,
    ]);
    $owner->save();
    SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_google',
      'provider_user_id' => 'google-9',
      'token' => 'old-token',
    ])->save();
    $this->setCurrentUser(new AnonymousUserSession());

    $authenticator = $this->authenticator();
    $authenticator->setPluginId('social_auth_google');
    $response = $authenticator->authenticateUser(new SocialAuthUser(
      'Ignored',
      'google-9',
      'new-token',
      'someone-else@example.com',
    ));

    $this->assertStringContainsString('/wheel', $response->getTargetUrl());
    $this->assertSame((int) $owner->id(), (int) $this->container->get('current_user')->id());
    $this->assertCount(1, $this->socialAuthIds());
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties([
      'mail' => 'someone-else@example.com',
    ]));
  }

  /**
   * Login-only mode does not create an account.
   */
  public function testLoginOnlySkipsRegistration(): void {
    $this->config('social_auth.settings')->set('user_allowed', 'login')->save();
    $this->setCurrentUser(new AnonymousUserSession());
    $authenticator = $this->authenticator();
    $authenticator->setPluginId('social_auth_google');
    $authenticator->authenticateUser(new SocialAuthUser(
      'Nope',
      'google-2',
      'token',
      'nope@example.com',
    ));

    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties([
      'mail' => 'nope@example.com',
    ]));
    $this->assertSame([], $this->socialAuthIds());
  }

  /**
   * Unlink refuses another person's record and the last remaining way in.
   */
  public function testUnlinkStaysWithTheOwner(): void {
    $owner = User::create([
      'name' => 'owner',
      'mail' => NULL,
      'status' => 1,
    ]);
    $owner->save();
    $other = User::create([
      'name' => 'other',
      'mail' => 'other@example.com',
      'status' => 1,
    ]);
    $other->save();
    $only = SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_google',
      'provider_user_id' => 'google-owner',
      'token' => 'token',
    ]);
    $only->save();
    $foreign = SocialAuth::create([
      'user_id' => $other->id(),
      'plugin_id' => 'social_auth_facebook',
      'provider_user_id' => 'facebook-other',
      'token' => 'token',
    ]);
    $foreign->save();

    $links = $this->container->get(ProviderLinks::class);
    $this->assertFalse($links->unlink((int) $foreign->id(), $owner));
    $this->assertFalse($links->unlink((int) $only->id(), $owner));
    $this->assertNotNull(SocialAuth::load($only->id()));
    $this->assertNotNull(SocialAuth::load($foreign->id()));

    $second = SocialAuth::create([
      'user_id' => $owner->id(),
      'plugin_id' => 'social_auth_facebook',
      'provider_user_id' => 'facebook-owner',
      'token' => 'token',
    ]);
    $second->save();
    $this->assertTrue($links->unlink((int) $second->id(), $owner));
    $this->assertNull(SocialAuth::load($second->id()));
    $this->assertNotNull(SocialAuth::load($only->id()));
  }

  /**
   * The container must be using the guarded Social Auth classes.
   */
  private function authenticator(): UserAuthenticator {
    $manager = $this->container->get('social_auth.user_manager');
    $authenticator = $this->container->get('social_auth.user_authenticator');
    $this->assertInstanceOf(UserManager::class, $manager);
    $this->assertInstanceOf(UserAuthenticator::class, $authenticator);
    return $authenticator;
  }

  /**
   * All social auth record ids.
   *
   * @return array<int|string, string>
   *   Query ids.
   */
  private function socialAuthIds(): array {
    return $this->container->get('entity_type.manager')->getStorage('social_auth')->getQuery()
      ->accessCheck(FALSE)
      ->execute();
  }

}
