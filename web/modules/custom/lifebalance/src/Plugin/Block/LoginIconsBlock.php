<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Service\LoginEntry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Header sign-in: a label and three icons for an anonymous visitor.
 */
#[Block(
  id: 'lifebalance_login_icons',
  admin_label: new TranslatableMarkup('Вхід'),
  category: new TranslatableMarkup('LifeBalance'),
)]
final class LoginIconsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the header sign-in.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\lifebalance\Service\LoginEntry $loginEntry
   *   Icons and the password dialog.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LoginEntry $loginEntry,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the header sign-in.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(LoginEntry::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The icons and, away from the login page, the password dialog.
   */
  public function build(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-login-entry']],
      '#cache' => [
        'contexts' => ['user', 'url.path'],
        'max-age' => 0,
      ],
      'icons' => $this->loginEntry->icons(),
    ];
    $dialog = $this->loginEntry->dialog();
    if ($dialog !== NULL) {
      $build['dialog'] = $dialog;
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAnonymous())->cachePerUser();
  }

}
