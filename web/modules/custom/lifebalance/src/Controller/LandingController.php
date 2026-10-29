<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\RadarGeometry;

/**
 * Public front page: a sample wheel and a create action.
 */
final class LandingController extends ControllerBase {

  /**
   * Illustrative scores. Every sector is full, matching the logo.
   *
   * @var array<string, int|null>
   */
  private const SAMPLE = [
    'health' => 10,
    'relationships' => 10,
    'environment' => 10,
    'purpose' => 10,
    'finances' => 10,
    'self_development' => 10,
    'life_brightness' => 10,
    'spirituality' => 10,
  ];

  public function __construct(
    protected SectorRepository $sectors,
    protected RadarGeometry $radarGeometry,
  ) {}

  /**
   * Landing content. Sign-in lives in the header and on the login page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    return [
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
      '#attached' => [
        'library' => ['lifebalance/landing'],
      ],
      'story' => $this->story(),
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-actions']],
        'create' => [
          '#type' => 'link',
          '#title' => $this->t('Створити власне колесо'),
          '#url' => Url::fromRoute('lifebalance.wheel'),
          '#attributes' => ['class' => ['lifebalance-create']],
        ],
      ],
    ];
  }

  /**
   * The public explanation, with the sample wheel under the opening.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function story(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-landing']],
      'intro' => [
        '#type' => 'container',
        'title' => $this->heading('h2', $this->t('Що таке колесо балансу')),
        'lead' => $this->paragraph($this->t('Колесо балансу — простий інструмент самоспостереження. Ви оцінюєте кожну сферу життя від 0 до 10: наскільки вона зараз така, як вам хочеться. З оцінок складається колесо. З нього одразу видно, які сфери наповнені, а яким бракує уваги.')),
        'snapshots' => $this->paragraph($this->t("Одне колесо — це знімок одного дня. Справжня цінність з'являється, коли таких знімків кілька: тоді видно, що змінюється, що стоїть на місці і куди варто спрямувати сили.")),
      ],
      'wheel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-landing__wheel']],
        'radar' => [
          '#theme' => 'lifebalance_radar',
          '#geometry' => $this->radarGeometry->build($this->chart()),
        ],
      ],
      'areas' => $this->areas(),
      'steps' => $this->steps(),
      'reasons' => $this->reasons(),
      'free' => $this->free(),
      'limits' => [
        '#type' => 'container',
        'title' => $this->heading('h2', $this->t('Чесно про межі')),
        'text' => $this->paragraph($this->t("Колесо балансу — авторський інструмент самоспостереження, а не психологічний тест, медична діагностика чи фінансова консультація. Оцінки суб'єктивні, і саме в цьому їхня цінність: вони показують, як ви самі сприймаєте своє життя. Якщо щось серйозно турбує, зверніться до фахівця.")),
      ],
    ];
  }

  /**
   * The eight areas and what each one covers.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function areas(): array {
    $build = [
      '#type' => 'container',
      'title' => $this->heading('h2', $this->t('Вісім сфер')),
      'list' => [
        '#theme' => 'item_list',
        '#list_type' => 'ul',
        '#attributes' => ['class' => ['lifebalance-landing__areas']],
        '#items' => [],
      ],
    ];
    $areas = [
      [$this->t("Здоров'я."), $this->t('Самопочуття, енергія, сон, рух, відновлення.')],
      [$this->t('Стосунки.'), $this->t('Близькі люди, довіра, відвертість, особисті кордони.')],
      [$this->t('Оточення.'), $this->t('Ширше коло спілкування і середовище, в якому ви живете.')],
      [$this->t('Покликання.'), $this->t('Робота, справа, самореалізація.')],
      [$this->t('Забезпеченість.'), $this->t('Доходи, витрати, фінансова стійкість, умови життя.')],
      [$this->t('Самовдосконалення.'), $this->t('Навчання, навички, робота над собою.')],
      [$this->t('Яскравість життя.'), $this->t('Відпочинок, хобі, враження, радість.')],
      [$this->t('Духовність.'), $this->t('Цінності, сенси, світогляд, творчість.')],
    ];
    foreach ($areas as $area) {
      $build['list']['#items'][] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ name }}</strong> {{ text }}',
        '#context' => [
          'name' => $area[0],
          'text' => $area[1],
        ],
      ];
    }
    return $build;
  }

  /**
   * How a person uses the wheel.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function steps(): array {
    return [
      '#type' => 'container',
      'title' => $this->heading('h2', $this->t('Як це працює')),
      'list' => [
        '#theme' => 'item_list',
        '#list_type' => 'ol',
        '#items' => [
          $this->t('Увійдіть через Google або Facebook. Пароль вигадувати не потрібно.'),
          $this->t('Оцініть сфери. Якщо складно одразу назвати число, дайте відповідь на 10 простих тверджень у кожній сфері, і система підкаже орієнтир.'),
          $this->t('Поставте власну оцінку. Остаточне слово завжди за вами. Орієнтир лише допомагає подумати.'),
          $this->t('Збережіть зріз і повертайтеся. Через тиждень, місяць чи пів року ви побачите свою динаміку: графіки по кожній сфері і порівняння двох дат.'),
        ],
      ],
    ];
  }

  /**
   * Why this wheel keeps a history.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function reasons(): array {
    $build = [
      '#type' => 'container',
      'title' => $this->heading('h2', $this->t('Чому варто вести колесо саме тут')),
    ];
    $reasons = [
      [
        $this->t('Історія, а не одноразовий тест.'),
        $this->t('Колесо на папері чи в онлайн-тесті показує лише «сьогодні». Тут кожен зріз зберігається з датою, тож ви бачите, як змінюється кожна сфера, і можете порівнювати себе з собою в будь-який момент.'),
      ],
      [
        $this->t('Орієнтири замість гадання.'),
        $this->t('Поставити «6 з 10» без опори складно, а наступного разу ця шістка означатиме вже щось інше. Прості твердження в кожній сфері допомагають оцінювати послідовно. Ви бачите, на чому ґрунтується оцінка.'),
      ],
      [
        $this->t('Ваша оцінка — ваша.'),
        $this->t('Система не вирішує за вас і не ставить «правильних» балів. Вона підказує, а рішення приймаєте ви.'),
      ],
      [
        $this->t('Чесна історія.'),
        $this->t('Збережений зріз не змінюється заднім числом. Навіть якщо ми колись оновимо формулювання тверджень, ви побачите саме ті, на які відповідали тоді. Помилковий зріз можна видалити.'),
      ],
      [
        $this->t('Приватно за замовчуванням.'),
        $this->t('Ваші оцінки, відповіді й нотатки бачите лише ви. Немає рейтингів, публічних профілів і порівнянь з іншими людьми. Членство в спільноті чи платний доступ нікому не відкривають ваших результатів.'),
      ],
      [
        $this->t('Ваші дані під вашим контролем.'),
        $this->t('Усі свої результати можна завантажити одним файлом або повністю видалити акаунт разом з даними.'),
      ],
      [
        $this->t('Зручно з телефона.'),
        $this->t("Оцінювання займає кілька хвилин і працює на телефоні, планшеті й комп'ютері. Інтерфейс українською, ним можна користуватися з клавіатури."),
      ],
      [
        $this->t('Без тиску.'),
        $this->t('Ми не лякаємо висновками і не обіцяємо змінити життя за тиждень. Це інструмент, який допомагає краще бачити себе і чесно відстежувати зміни.'),
      ],
    ];
    foreach ($reasons as $index => $reason) {
      $build['h' . $index] = $this->heading('h3', $reason[0]);
      $build['p' . $index] = $this->paragraph($reason[1]);
    }
    return $build;
  }

  /**
   * What stays free, and what extended access adds.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function free(): array {
    return [
      '#type' => 'container',
      'title' => $this->heading('h2', $this->t('Безкоштовно і назавжди')),
      'list' => [
        '#theme' => 'item_list',
        '#list_type' => 'ul',
        '#items' => [
          $this->t('Власне колесо з 8 сферами'),
          $this->t('80 базових тверджень-орієнтирів'),
          $this->t('Необмежена кількість зрізів та історія'),
          $this->t('Графіки динаміки і порівняння двох дат'),
        ],
      ],
      'more' => $this->paragraph($this->t('Розширений доступ (поки що за запрошенням): ще 80 поглиблених тверджень, підказки для саморефлексії за вашими відповідями, особисті цілі з чеклістами і план на 30 днів.')),
    ];
  }

  /**
   * One heading.
   *
   * @return array<string, mixed>
   *   A render element.
   */
  private function heading(string $tag, TranslatableMarkup $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => $tag,
      '#value' => $text,
    ];
  }

  /**
   * One paragraph.
   *
   * @return array<string, mixed>
   *   A render element.
   */
  private function paragraph(TranslatableMarkup $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
    ];
  }

  /**
   * Sample sectors for the public radar.
   *
   * @return list<array{label: string, color: string, value: int|null}>
   *   Chart input.
   */
  private function chart(): array {
    $chart = [];
    foreach ($this->sectors->loadActive() as $id => $sector) {
      $chart[] = [
        'label' => (string) $sector->label(),
        'color' => $sector->getColor(),
        'value' => self::SAMPLE[$id] ?? NULL,
      ];
    }
    return $chart;
  }

}
