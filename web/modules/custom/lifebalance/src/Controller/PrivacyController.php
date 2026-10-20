<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lifebalance\Seo\PublicMeta;

/**
 * Public privacy notice. Seller requisites are still marked for the owner.
 */
final class PrivacyController extends ControllerBase {

  public function __construct(
    protected PublicMeta $publicMeta,
  ) {}

  /**
   * Privacy notice with items the site owner still has to fill in.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $build = [];
    foreach ($this->paragraphs() as $index => $text) {
      $build['p' . $index] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $text,
      ];
    }
    $build['faq'] = [
      '#type' => 'link',
      '#title' => $this->t('Запитання'),
      '#url' => Url::fromRoute('lifebalance.faq'),
    ];
    $build['terms'] = [
      '#type' => 'link',
      '#title' => $this->t('Оферта'),
      '#url' => Url::fromRoute('lifebalance.terms'),
    ];
    $build['refunds'] = [
      '#type' => 'link',
      '#title' => $this->t('Повернення'),
      '#url' => Url::fromRoute('lifebalance.refunds'),
    ];
    $meta = $this->publicMeta->elements('lifebalance.privacy');
    if (isset($meta['#attached'])) {
      $build['#attached'] = $meta['#attached'];
    }
    return $build;
  }

  /**
   * Notice paragraphs.
   *
   * @return list<\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Paragraphs in display order.
   */
  private function paragraphs(): array {
    return [
      $this->t('Самооцінка в LifeBalance не є діагностикою і не замінює консультацію фахівця.'),
      $this->t('Відвідування публічних сторінок може рахувати Google Analytics, якщо власник увімкнув лічильник. Оцінки, відповіді, нотатки й адреси особистих сторінок туди не передаються.'),
      $this->t('Твердження каталогу не проходили психометричної валідації.'),
      $this->t("Щоб видалити акаунт, увійдіть і відкрийте сторінку «Мої дані». Підтвердження видаляє зрізи, відповіді, нотатки, чернетку і прив'язку Google або Facebook."),
      $this->t('TODO для власника сайту: вкажіть юридичну особу, контактну адресу і юрисдикцію.'),
      $this->t('Якщо ви просите Facebook видалити дані, видаліть акаунт на сторінці «Мої дані». TODO для власника: додайте в застосунок Meta посилання на цю сторінку як інструкцію видалення даних і, якщо Meta цього вимагає, окрему адресу callback.'),
      $this->t('Технічний доступ до бази має адміністратор сервера. Резервні копії теж містять ці дані. Адміністратор сайту не бачить зрізи в інтерфейсі.'),
      $this->t('TODO для власника: опишіть, як довго зберігаються резервні копії і коли особисті дані з них видаляються.'),
      $this->t('Оплата приймається на сторінці платіжного сервісу monobank. Номер картки на цей сайт не передається.'),
      $this->t('Після оплати зберігаються план, сума, валюта, статус, дати і номер рахунку провайдера. Оцінки і нотатки в замовлення не записуються.'),
      $this->t("Якщо акаунт видалено, замовлення лишається для обліку, а зв'язок з акаунтом зникає."),
      $this->t('TODO для власника: вкажіть, як довго зберігаються замовлення.'),
    ];
  }

}
