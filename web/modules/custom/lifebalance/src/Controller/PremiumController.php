<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance\Seo\PublicMeta;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Compares the basic and extended assessment. There is no checkout here.
 */
final class PremiumController extends ControllerBase {

  public function __construct(
    protected PublicMeta $publicMeta,
  ) {}

  /**
   * The comparison, while the site flag allows the page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $enabled = (bool) $this->config('lifebalance.settings')->get('premium_enabled');
    if (!$enabled) {
      throw new NotFoundHttpException();
    }
    $build = [
      '#cache' => [
        'tags' => ['config:lifebalance.settings'],
      ],
      'invite' => $this->paragraph($this->t('Доступ поки за запрошенням. Його відкриває людина, яка супроводжує сайт.')),
      'lead' => $this->paragraph($this->t('Базове оцінювання входить до кожного облікового запису. Розширене додає поглиблені твердження.')),
      'free_title' => $this->heading($this->t('Базове')),
      'free' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('10 тверджень у кожній із восьми сфер.'),
          $this->t('Орієнтир за цими твердженнями.'),
          $this->t('Колесо, історія, порівняння і динаміка.'),
        ],
      ],
      'premium_title' => $this->heading($this->t('Розширене')),
      'premium' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Ще 10 поглиблених тверджень у кожній сфері.'),
          $this->t('Орієнтир враховує всі 20 тверджень сфери.'),
          $this->t('Збережені відповіді лишаються в історії після завершення доступу.'),
        ],
      ],
      'note' => $this->paragraph($this->t('Самооцінка не є діагностикою.')),
    ];
    $meta = $this->publicMeta->elements('lifebalance.premium');
    if (isset($meta['#attached'])) {
      $build['#attached'] = $meta['#attached'];
    }
    $this->moduleHandler()->alter('lifebalance_premium_page', $build);
    return $build;
  }

  /**
   * One paragraph.
   *
   * @param \Stringable|string $text
   *   Paragraph text.
   *
   * @return array<string, mixed>
   *   A paragraph render element.
   */
  private function paragraph(\Stringable|string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
    ];
  }

  /**
   * A section heading.
   *
   * @param \Stringable|string $text
   *   Heading text.
   *
   * @return array<string, mixed>
   *   A heading render element.
   */
  private function heading(\Stringable|string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $text,
    ];
  }

}
