<?php

declare(strict_types=1);

namespace Drupal\lifebalance;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\lifebalance\SocialAuth\UserAuthenticator;
use Drupal\lifebalance\SocialAuth\UserManager;

/**
 * Replaces Social Auth user services with the guarded subclasses.
 */
final class LifebalanceServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $this->swap($container, 'social_auth.user_manager', UserManager::class);
    $this->swap($container, 'social_auth.user_authenticator', UserAuthenticator::class);
  }

  /**
   * Points a Social Auth service at a LifeBalance subclass.
   */
  private function swap(ContainerBuilder $container, string $id, string $class): void {
    if (!$container->hasDefinition($id)) {
      return;
    }
    $container->getDefinition($id)->setClass($class);
  }

}
