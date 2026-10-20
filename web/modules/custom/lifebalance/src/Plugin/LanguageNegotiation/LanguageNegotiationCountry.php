<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\LanguageNegotiation;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\Attribute\LanguageNegotiation;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\language\LanguageNegotiationMethodBase;
use Drupal\language\LanguageNegotiationMethodInterface;
use Drupal\lifebalance\Service\VisitorCountry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Picks the interface language from the visitor's country.
 *
 * Ukraine is Ukrainian, Russia is Russian, and every other public country
 * is English. An explicit switcher choice is a separate method and wins.
 */
#[LanguageNegotiation(
  id: LanguageNegotiationCountry::METHOD_ID,
  name: new TranslatableMarkup('Country'),
  types: [LanguageInterface::TYPE_INTERFACE],
  weight: -5,
  description: new TranslatableMarkup(
    'Language from the country of the visitor address.',
  ),
)]
final class LanguageNegotiationCountry extends LanguageNegotiationMethodBase implements
  LanguageNegotiationMethodInterface,
  ContainerFactoryPluginInterface {

  /**
   * The language negotiation method id.
   */
  public const METHOD_ID = 'language-country';

  public function __construct(
    private readonly KillSwitch $pageCacheKillSwitch,
    private readonly VisitorCountry $countries,
  ) {}

  /**
   * Creates the negotiation method.
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
      $container->get('page_cache_kill_switch'),
      $container->get(VisitorCountry::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return string|null
   *   A language code, or NULL when the address has no country.
   */
  public function getLangcode(?Request $request = NULL): ?string {
    // The country is not part of the URL, so a shared page cache would
    // serve one visitor's language to another.
    $this->pageCacheKillSwitch->trigger();
    if ($this->languageManager === NULL) {
      return NULL;
    }
    $country = $this->countries->code($request);
    if ($country === NULL) {
      return NULL;
    }
    $languages = $this->languageManager->getLanguages();
    $code = match ($country) {
      'UA' => 'uk',
      'RU' => 'ru',
      default => 'en',
    };
    if (isset($languages[$code])) {
      return $code;
    }
    return isset($languages['en']) ? 'en' : NULL;
  }

}
