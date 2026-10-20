<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheOptionalInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Language switcher as a short list: UK, RU, EN.
 */
#[Block(
  id: 'lifebalance_language_switcher',
  admin_label: new TranslatableMarkup('Language'),
  category: new TranslatableMarkup('LifeBalance'),
)]
final class LanguageSwitcherBlock extends BlockBase implements
  ContainerFactoryPluginInterface,
  CacheOptionalInterface {

  /**
   * Abbreviations shown in the list. The codes are language codes.
   */
  private const LABELS = [
    'uk' => 'UK',
    'ru' => 'RU',
    'en' => 'EN',
  ];

  /**
   * Builds the switcher.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LanguageManagerInterface $languageManager,
    protected RouteMatchInterface $routeMatch,
    protected RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the switcher.
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
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The switcher.
   */
  public function build(): array {
    $switch = $this->languageManager->getLanguageSwitchLinks(
      LanguageInterface::TYPE_INTERFACE,
      Url::fromRouteMatch($this->routeMatch),
    );
    $links = is_object($switch) && isset($switch->links) && is_array($switch->links)
      ? $switch->links
      : [];
    $current = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $options = [];
    foreach (self::LABELS as $code => $label) {
      if (!isset($links[$code]['url']) || !$links[$code]['url'] instanceof Url) {
        continue;
      }
      $options[] = [
        'code' => $code,
        'label' => $label,
        'url' => $this->switchUrl($links[$code])->toString(),
        'selected' => $code === $current,
      ];
    }
    if ($options === []) {
      return [];
    }
    $request = $this->requestStack->getCurrentRequest();
    $action = '/';
    if ($request !== NULL) {
      $action = $request->getBaseUrl() . $request->getPathInfo();
    }
    return [
      '#theme' => 'lifebalance_language_switcher',
      '#options' => $options,
      '#action' => $action,
      '#attached' => [
        'library' => ['lifebalance/language-switcher'],
      ],
    ];
  }

  /**
   * Puts the session language onto the switch link.
   *
   * The session method keeps `language` beside the URL. Without it every
   * option points at the current path and the list cannot change language.
   *
   * @param array<string, mixed> $link
   *   One language switch link.
   */
  private function switchUrl(array $link): Url {
    $url = $link['url'];
    if (!$url instanceof Url) {
      return Url::fromRoute('<front>');
    }
    $query = $link['query'] ?? NULL;
    if (!is_array($query) || $query === []) {
      return $url;
    }
    $url = clone $url;
    $existing = $url->getOption('query');
    if (!is_array($existing)) {
      $existing = [];
    }
    $url->setOption('query', $query + $existing);
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(count($this->languageManager->getLanguages()) > 1)
      ->addCacheTags(['config:configurable_language_list']);
  }

}
