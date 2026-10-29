<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance\Seo\PublicMeta;

/**
 * Public offer and refund stubs. The seller's legal text is not filled in.
 */
final class PolicyController extends ControllerBase {

  public function __construct(
    protected PublicMeta $publicMeta,
  ) {}

  /**
   * Offer stub. It is not a contract until the owner replaces it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function terms(): array {
    return $this->page(
      'lifebalance.terms',
      [
        $this->t('Це заготовка публічної оферти. Вона ще не є договором.'),
        $this->t('TODO для власника: вкажіть назву продавця, податковий код, адресу, контакт і додайте текст оферти.'),
        $this->t('Оплата відкриває розширене оцінювання на строк обраного плану. Ціна фіксується в замовленні на момент переходу до оплати.'),
        $this->t('Оплата йде на сторінку платіжного сервісу. Цей сайт не отримує номер картки.'),
        $this->t('Доступ після оплати відкривається після підтвердження платежу, не після повернення з платіжної сторінки.'),
      ],
    );
  }

  /**
   * Refund stub. Periods and requisites are still the owner's text.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function refunds(): array {
    return $this->page(
      'lifebalance.refunds',
      [
        $this->t('Це заготовка політики повернення. Строки й реквізити має додати власник сайту.'),
        $this->t('TODO для власника: додайте строк звернення, спосіб звернення і реквізити для повернення.'),
        $this->t('Повне повернення підтвердженої оплати завершує доступ із цього платежу. Зрізи, відповіді, нотатки і цілі залишаються.'),
        $this->t('Часткове повернення саме не завершує доступ. Рішення про доступ ухвалюється окремо.'),
        $this->t('Повернення оформлюється у продавця. Номер картки на цьому сайті не вводиться.'),
      ],
    );
  }

  /**
   * Paragraphs and the public metatag pair.
   *
   * @param string $route
   *   Public route that supplies the title and description.
   * @param list<\Drupal\Core\StringTranslation\TranslatableMarkup> $paragraphs
   *   Notice paragraphs.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function page(string $route, array $paragraphs): array {
    $build = [];
    foreach ($paragraphs as $index => $text) {
      $build['p' . $index] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $text,
      ];
    }
    $meta = $this->publicMeta->elements($route);
    if (isset($meta['#attached'])) {
      $build['#attached'] = $meta['#attached'];
    }
    return $build;
  }

}
