<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Install;

use Drupal\block\Entity\Block;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Core\Language\LanguageManager;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\PoDatabaseWriter;

/**
 * Turns on Ukrainian, Russian, and English.
 *
 * An explicit switcher choice wins. Otherwise the visitor's country selects
 * the language: Ukraine is Ukrainian, Russia is Russian, and every other
 * public country is English.
 */
final class InterfaceLanguage {

  /**
   * Configures languages, negotiation, and the English interface.
   */
  public static function apply(): void {
    self::languages();
    self::negotiation();
    self::translations();
    self::switchers();
    self::translatedConfig();
  }

  /**
   * Adds Ukrainian and makes it the site default.
   */
  private static function languages(): void {
    if (ConfigurableLanguage::load('uk') === NULL) {
      $language = ConfigurableLanguage::createFromLangcode('uk');
      $standard = LanguageManager::getStandardLanguageList();
      $language->set('label', $standard['uk'][1]);
      $language->setWeight(0);
      $language->save();
    }
    if (ConfigurableLanguage::load('ru') === NULL) {
      $language = ConfigurableLanguage::createFromLangcode('ru');
      $standard = LanguageManager::getStandardLanguageList();
      $language->set('label', $standard['ru'][1]);
      $language->setWeight(1);
      $language->save();
    }
    $english = ConfigurableLanguage::load('en');
    if ($english !== NULL && (int) $english->getWeight() < 2) {
      $english->setWeight(2);
      $english->save();
    }
    $site = \Drupal::configFactory()->getEditable('system.site');
    $site->set('default_langcode', 'uk');
    $site->set('langcode', 'uk');
    $site->save();
    $locale = \Drupal::configFactory()->getEditable('locale.settings');
    $locale->set('translate_english', TRUE);
    $locale->save();
  }

  /**
   * Session choice, then the country, then Ukrainian.
   */
  private static function negotiation(): void {
    $negotiation = \Drupal::configFactory()->getEditable('language.negotiation');
    $negotiation->set('selected_langcode', 'uk');
    $negotiation->set('session.parameter', 'language');
    $negotiation->set('url.source', 'path_prefix');
    $negotiation->set('url.prefixes', [
      'uk' => '',
      'ru' => 'ru',
      'en' => 'en',
    ]);
    $negotiation->save();
    $types = \Drupal::configFactory()->getEditable('language.types');
    $types->set('negotiation.language_interface.enabled', [
      'language-session' => -10,
      'language-country' => -5,
      'language-selected' => 12,
    ]);
    $types->save();
  }

  /**
   * Imports the shipped English and Russian interface translations.
   */
  private static function translations(): void {
    foreach (['en', 'ru'] as $langcode) {
      self::importCatalog($langcode);
    }
    \Drupal::service('string_translation')->reset();
  }

  /**
   * Imports one shipped catalog when the file is present.
   */
  private static function importCatalog(string $langcode): void {
    $directory = \Drupal::service('extension.list.module')->getPath('lifebalance');
    $uri = \Drupal::root() . '/' . $directory . '/translations/custom.' . $langcode . '.po';
    if (!is_file($uri)) {
      return;
    }
    $reader = new PoStreamReader();
    $reader->setURI($uri);
    $reader->setLangcode($langcode);
    $reader->open();
    $writer = new PoDatabaseWriter();
    $writer->setLangcode($langcode);
    $writer->setOptions([
      'overwrite_options' => [
        'not_customized' => TRUE,
        'customized' => TRUE,
      ],
      'customized' => 1,
    ]);
    $writer->writeItems($reader);
    $reader->close();
  }

  /**
   * Places a language switcher on the active theme and this theme.
   */
  private static function switchers(): void {
    if (!\Drupal::moduleHandler()->moduleExists('block')) {
      return;
    }
    $themes = [];
    $default = \Drupal::config('system.theme')->get('default');
    if (is_string($default) && $default !== '') {
      $themes[] = $default;
    }
    $handler = \Drupal::service('theme_handler');
    if ($handler->themeExists('lifebalance_theme')) {
      $themes[] = 'lifebalance_theme';
    }
    foreach (array_unique($themes) as $theme) {
      self::placeSwitcher($theme);
    }
  }

  /**
   * Places one switcher block when the theme has a header region.
   */
  private static function placeSwitcher(string $theme): void {
    $handler = \Drupal::service('theme_handler');
    if (!$handler->themeExists($theme)) {
      return;
    }
    $regions = $handler->getTheme($theme)->info['regions'] ?? [];
    if (isset($regions['navbar_right'])) {
      $region = 'navbar_right';
    }
    elseif (isset($regions['header'])) {
      $region = 'header';
    }
    else {
      return;
    }
    $id = $theme . '_languageswitcher';
    $storage = \Drupal::entityTypeManager()->getStorage('block');
    $existing = $storage->load($id);
    if ($existing instanceof Block) {
      $placed = $existing->getPluginId() === 'lifebalance_language_switcher'
        && $existing->getRegion() === $region;
      if ($placed) {
        return;
      }
      $existing->delete();
    }
    $values = [
      'id' => $id,
      'plugin' => 'lifebalance_language_switcher',
      'theme' => $theme,
      'region' => $region,
      'weight' => 10,
      'status' => TRUE,
      'settings' => [
        'label' => 'Language switcher',
        'label_display' => '0',
      ],
    ];
    if ($id === 'lifebalance_theme_languageswitcher') {
      $values['uuid'] = 'a7c3e1d4-6b28-4f0a-9c55-1e8d4b2a7f60';
    }
    Block::create($values)->save();
  }

  /**
   * English and Russian labels for catalog config in Ukrainian.
   */
  private static function translatedConfig(): void {
    foreach (['en', 'ru'] as $langcode) {
      $storage = \Drupal::service('config.storage');
      $collection = $storage->createCollection('language.' . $langcode);
      foreach (self::overrides($langcode) as $name => $values) {
        if (\Drupal::config($name)->isNew()) {
          continue;
        }
        $existing = $collection->read($name);
        $merged = array_merge(is_array($existing) ? $existing : [], $values);
        $collection->write($name, $merged);
      }
    }
    foreach (array_keys(self::overrides('en')) as $name) {
      if (!str_starts_with($name, 'lifebalance.sector.')) {
        continue;
      }
      $config = \Drupal::configFactory()->getEditable($name);
      if (!$config->isNew() && $config->get('langcode') !== 'uk') {
        $config->set('langcode', 'uk')->save();
      }
    }
  }

  /**
   * Config overrides for one interface language.
   *
   * @return array<string, array<string, mixed>>
   *   Values replaced when the interface language matches.
   */
  private static function overrides(string $langcode): array {
    $catalogs = [
      'en' => self::englishOverrides(),
      'ru' => self::russianOverrides(),
    ];
    return $catalogs[$langcode] ?? [];
  }

  /**
   * English config overrides keyed by config name.
   *
   * @return array<string, array<string, mixed>>
   *   Values replaced when the interface language is English.
   */
  private static function englishOverrides(): array {
    return [
      'system.site' => [
        'slogan' => 'Balance your life.',
      ],
      'metatag.metatag_defaults.front' => [
        'tags' => [
          'title' => 'Balance wheel: self-assessment of eight '
          . 'areas | LifeBalance',
          'description' => 'Balance your life. A self-assessment of eight '
          . 'areas of life. The result is not a diagnosis.',
        ],
      ],
      'lifebalance.sector.health' => [
        'label' => 'Health',
        'description' => 'How you feel, appearance, mobility, mood, '
        . 'walks, energy, food, routine, sport, and sleep.',
      ],
      'lifebalance.sector.relationships' => [
        'label' => 'Relationships',
        'description' => 'Close people: conversation, friendship, love, '
        . 'and family.',
      ],
      'lifebalance.sector.environment' => [
        'label' => 'Environment',
        'description' => 'The wider circle: relatives, colleagues, '
        . 'friends, neighbors, and opponents.',
      ],
      'lifebalance.sector.purpose' => [
        'label' => 'Purpose',
        'description' => 'Work, career, business, profession, '
        . 'employment, and social status.',
      ],
      'lifebalance.sector.finances' => [
        'label' => 'Finances',
        'description' => 'Income, spending, and living conditions.',
      ],
      'lifebalance.sector.self_development' => [
        'label' => 'Self-development',
        'description' => 'Learning, work on yourself, and personal growth.',
      ],
      'lifebalance.sector.life_brightness' => [
        'label' => 'Brightness of life',
        'description' => 'Fun, rest, hobbies, travel, and experiences.',
      ],
      'lifebalance.sector.spirituality' => [
        'label' => 'Spirituality',
        'description' => 'Faith, creativity, and art.',
      ],
      'lifebalance_billing.plan.premium_30' => [
        'label' => '30 days',
      ],
      'lifebalance_billing.plan.premium_365' => [
        'label' => '365 days',
      ],
    ];
  }

  /**
   * Russian config overrides keyed by config name.
   *
   * @return array<string, array<string, mixed>>
   *   Values replaced when the interface language is Russian.
   */
  private static function russianOverrides(): array {
    return [
      'system.site' => [
        'slogan' => 'Сбалансируй свою жизнь.',
      ],
      'metatag.metatag_defaults.front' => [
        'tags' => [
          'title' => 'Колесо баланса: самооценка восьми сфер | LifeBalance',
          'description' => 'Сбалансируй свою жизнь. Самооценка восьми сфер '
          . 'жизни. Результат не является диагностикой.',
        ],
      ],
      'lifebalance.sector.health' => [
        'label' => 'Здоровье',
        'description' => 'Самочувствие, внешность, подвижность, '
        . 'настроение, прогулки, бодрость, питание, режим, спорт и сон.',
      ],
      'lifebalance.sector.relationships' => [
        'label' => 'Отношения',
        'description' => 'Близкие люди: общение, дружба, любовь и семья.',
      ],
      'lifebalance.sector.environment' => [
        'label' => 'Окружение',
        'description' => 'Более широкий круг: родственники, коллеги, '
        . 'друзья, соседи и оппоненты.',
      ],
      'lifebalance.sector.purpose' => [
        'label' => 'Призвание',
        'description' => 'Работа, карьера, бизнес, профессия, '
        . 'занятость и социальный статус.',
      ],
      'lifebalance.sector.finances' => [
        'label' => 'Обеспеченность',
        'description' => 'Доходы, расходы и условия жизни.',
      ],
      'lifebalance.sector.self_development' => [
        'label' => 'Саморазвитие',
        'description' => 'Учёба, работа над собой и личностный рост.',
      ],
      'lifebalance.sector.life_brightness' => [
        'label' => 'Яркость жизни',
        'description' => 'Развлечения, отдых, хобби, путешествия '
        . 'и впечатления.',
      ],
      'lifebalance.sector.spirituality' => [
        'label' => 'Духовность',
        'description' => 'Вера, творчество и искусство.',
      ],
      'lifebalance_billing.plan.premium_30' => [
        'label' => '30 дней',
      ],
      'lifebalance_billing.plan.premium_365' => [
        'label' => '365 дней',
      ],
    ];
  }

}
