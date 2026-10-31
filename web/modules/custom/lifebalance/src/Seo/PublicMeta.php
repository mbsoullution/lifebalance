<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Seo;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\metatag\MetatagManager;

/**
 * Titles, canonical URLs, and language alternates for public pages.
 *
 * Personal routes stay out of the index. Measurement stays on this site.
 */
final class PublicMeta {

  /**
   * Routes a search engine may index.
   *
   * @var list<string>
   */
  private const PUBLIC_ROUTES = [
    'lifebalance.landing',
    'lifebalance.faq',
    'lifebalance.privacy',
    'lifebalance.terms',
    'lifebalance.refunds',
    'lifebalance.premium',
  ];

  /**
   * Routes listed in the sitemap while the page exists for everyone.
   *
   * @var list<string>
   */
  private const SITEMAP_ROUTES = [
    'lifebalance.faq',
    'lifebalance.privacy',
    'lifebalance.terms',
    'lifebalance.refunds',
  ];

  public function __construct(
    private readonly ?MetatagManager $metatagManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * Whether search engines may index this route.
   *
   * @param string|null $route
   *   The route name, when the match has one.
   */
  public function isPublicRoute(?string $route): bool {
    return is_string($route) && in_array($route, self::PUBLIC_ROUTES, TRUE);
  }

  /**
   * Title, description, canonical URL, and an index directive.
   *
   * @return array<string, mixed>
   *   A render array with #attached meta tags.
   */
  public function elements(string $route): array {
    if ($this->metatagManager === NULL) {
      return [];
    }
    $copy = $this->copy($route);
    return $this->metatagManager->generateElements([
      'title' => $copy['title'],
      'description' => $copy['description'],
      'robots' => 'index, follow',
    ]);
  }

  /**
   * Open Graph and hreflang on public pages, noindex on the rest.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   */
  public function attach(array &$attachments): void {
    $route = $this->routeMatch->getRouteName();
    if (!is_string($route) || $route === '') {
      return;
    }
    $attachments['#cache']['contexts'][] = 'languages:language_interface';
    $attachments['#cache']['contexts'][] = 'url.path';
    if (!in_array($route, self::PUBLIC_ROUTES, TRUE)) {
      $this->meta($attachments, 'robots', 'noindex, nofollow');
      return;
    }
    $copy = $this->copy($route);
    $canonical = $this->absolute($route);
    $this->canonical($attachments, $canonical);
    $this->alternates($attachments, $route);
    $this->social($attachments, $copy['title'], $copy['description'], $canonical);
    if ($route === 'lifebalance.landing') {
      $attachments['#cache']['tags'][] = 'config:metatag.metatag_defaults.front';
    }
  }

  /**
   * Public URLs in every interface language.
   */
  public function sitemap(): CacheableResponse {
    $locs = [];
    foreach ($this->languageManager->getLanguages() as $language) {
      $locs[] = $this->absolute('lifebalance.landing', $language);
      foreach (self::SITEMAP_ROUTES as $route) {
        $locs[] = $this->absolute($route, $language);
      }
      if ($this->premiumEnabled()) {
        $locs[] = $this->absolute('lifebalance.premium', $language);
      }
    }
    $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
    $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($locs as $loc) {
      $xml[] = '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc></url>';
    }
    $xml[] = '</urlset>';
    $response = new CacheableResponse(implode("\n", $xml) . "\n");
    $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
    $meta = new CacheableMetadata();
    $meta->addCacheTags(['config:lifebalance.settings']);
    $meta->addCacheContexts(['languages:language_interface']);
    $response->addCacheableDependency($meta);
    return $response;
  }

  /**
   * Title and description for one public route.
   *
   * @return array{title: string, description: string}
   *   Strings already translated for the current interface language.
   */
  private function copy(string $route): array {
    if ($route === 'lifebalance.landing') {
      $tags = $this->configFactory->get('metatag.metatag_defaults.front')->get('tags');
      $title = 'LifeBalance';
      $description = '';
      if (is_array($tags)) {
        if (is_string($tags['title'] ?? NULL)) {
          $title = $tags['title'];
        }
        if (is_string($tags['description'] ?? NULL)) {
          $description = $tags['description'];
        }
      }
      return ['title' => $title, 'description' => $description];
    }
    $pairs = [
      'lifebalance.faq' => [
        'Часті запитання — Колесо балансу',
        'Відповіді про оцінювання, приватність, історію, '
        . 'розширений доступ і оплату.',
      ],
      'lifebalance.privacy' => [
        'Конфіденційність | LifeBalance',
        'Як зберігається самооцінка і як видалити акаунт.',
      ],
      'lifebalance.terms' => [
        'Оферта | LifeBalance',
        'Заготовка публічної оферти. Реквізити продавця ще не додані.',
      ],
      'lifebalance.refunds' => [
        'Повернення | LifeBalance',
        'Заготовка політики повернення. Строк і реквізити ще не додані.',
      ],
      'lifebalance.premium' => [
        'Розширене оцінювання | LifeBalance',
        'Порівняння базового і розширеного оцінювання. '
        . 'Доступ поки за запрошенням.',
      ],
    ];
    $pair = $pairs[$route] ?? ['LifeBalance', ''];
    return [
      'title' => (string) $this->translation->translate($pair[0]),
      'description' => (string) $this->translation->translate($pair[1]),
    ];
  }

  /**
   * Absolute URL of a public route in one interface language.
   *
   * The default language stays on the clean path. Other languages use the
   * language query, which is what actually switches the interface.
   */
  private function absolute(string $route, ?LanguageInterface $language = NULL): string {
    $language = $language ?? $this->languageManager->getCurrentLanguage();
    $name = $route === 'lifebalance.landing' ? '<front>' : $route;
    $default = $this->languageManager->getDefaultLanguage()->getId();
    $url = Url::fromRoute($name, [], [
      'absolute' => TRUE,
      'language' => $language,
      'query' => ['language' => $language->getId()],
    ])->toString();
    if ($language->getId() !== $default) {
      return $url;
    }
    $url = preg_replace('/([?&])language=' . preg_quote($default, '/') . '(?=&|$)/', '', $url) ?? $url;
    return rtrim($url, '?&');
  }

  /**
   * The preferred URL of this language version.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $url
   *   Absolute canonical URL.
   */
  private function canonical(array &$attachments, string $url): void {
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'canonical',
          'href' => $url,
        ],
      ],
      'lifebalance_canonical',
    ];
  }

  /**
   * Alternate links for each interface language and x-default.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $route
   *   Public route being rendered.
   */
  private function alternates(array &$attachments, string $route): void {
    $default = $this->languageManager->getDefaultLanguage();
    foreach ($this->languageManager->getLanguages() as $language) {
      $this->link(
        $attachments,
        $language->getId(),
        $this->absolute($route, $language),
      );
    }
    $this->link($attachments, 'x-default', $this->absolute($route, $default));
  }

  /**
   * One hreflang alternate.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $langcode
   *   Language code, or x-default.
   * @param string $url
   *   Absolute alternate URL.
   */
  private function link(array &$attachments, string $langcode, string $url): void {
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'alternate',
          'hreflang' => $langcode,
          'href' => $url,
        ],
      ],
      'lifebalance_hreflang_' . $langcode,
    ];
  }

  /**
   * Open Graph and a summary card. The image is the wheel mark.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $title
   *   Page title for this language.
   * @param string $description
   *   Page description for this language.
   * @param string $url
   *   Canonical absolute URL.
   */
  private function social(array &$attachments, string $title, string $description, string $url): void {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $locales = ['uk' => 'uk_UA', 'ru' => 'ru_RU', 'en' => 'en_US'];
    $image = Url::fromUri('base:themes/custom/lifebalance_theme/images/share.png', [
      'absolute' => TRUE,
    ])->toString();
    $this->property($attachments, 'og:title', $title);
    $this->property($attachments, 'og:description', $description);
    $this->property($attachments, 'og:url', $url);
    $this->property($attachments, 'og:type', 'website');
    $this->property($attachments, 'og:site_name', 'LifeBalance');
    $this->property($attachments, 'og:locale', $locales[$langcode] ?? 'uk_UA');
    $this->property($attachments, 'og:image', $image);
    $this->meta($attachments, 'twitter:card', 'summary');
    $this->meta($attachments, 'twitter:title', $title);
    $this->meta($attachments, 'twitter:description', $description);
    $this->meta($attachments, 'twitter:image', $image);
  }

  /**
   * One property meta element.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $property
   *   Open Graph property name.
   * @param string $content
   *   Property value.
   */
  private function property(array &$attachments, string $property, string $content): void {
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'property' => $property,
          'content' => $content,
        ],
      ],
      'lifebalance_' . str_replace(':', '_', $property),
    ];
  }

  /**
   * One named meta element.
   *
   * @param array<string, mixed> $attachments
   *   Page attachments to extend.
   * @param string $name
   *   Meta name.
   * @param string $content
   *   Meta value.
   */
  private function meta(array &$attachments, string $name, string $content): void {
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => $name,
          'content' => $content,
        ],
      ],
      'lifebalance_' . str_replace(':', '_', $name),
    ];
  }

  /**
   * Whether the public comparison page is switched on.
   */
  private function premiumEnabled(): bool {
    return (bool) $this->configFactory->get('lifebalance.settings')->get('premium_enabled');
  }

}
