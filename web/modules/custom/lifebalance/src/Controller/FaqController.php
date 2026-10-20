<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Seo\PublicMeta;

/**
 * Public answers about the wheel, access, and personal data.
 */
final class FaqController extends ControllerBase {

  public function __construct(
    protected PublicMeta $publicMeta,
  ) {}

  /**
   * Frequently asked questions. The page has no personal data.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $build = [
      'lead' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Короткі відповіді про колесо, зрізи, доступ і дані.'),
      ],
    ];
    $build['questions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-faq']],
      '#attached' => [
        'library' => ['lifebalance/faq'],
      ],
    ];
    foreach ($this->items() as $index => $item) {
      $answers = [];
      foreach ($item['answers'] as $answer_index => $answer) {
        $answers['p' . $answer_index] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $answer,
        ];
      }
      $build['questions']['q' . $index] = [
        '#type' => 'details',
        '#title' => $item['question'],
        '#open' => FALSE,
      ] + $answers;
    }
    $meta = $this->publicMeta->elements('lifebalance.faq');
    if (isset($meta['#attached'])) {
      $build['#attached'] = $meta['#attached'];
    }
    return $build;
  }

  /**
   * Questions and answers in display order.
   *
   * @return list<array{question: \Drupal\Core\StringTranslation\TranslatableMarkup, answers: list<\Drupal\Core\StringTranslation\TranslatableMarkup>}>
   *   FAQ items.
   */
  private function items(): array {
    return [
      $this->item($this->t('Що це за сайт?'), [
        $this->t('LifeBalance зберігає датований зріз.'),
        $this->t('У зрізі вісім сфер життя.'),
        $this->t('Це самооцінка, а не діагностика.'),
        $this->t('Вона не замінює консультацію фахівця.'),
      ]),
      $this->item($this->t('Як почати?'), [
        $this->t('Увійдіть через Google, Facebook або пароль.'),
        $this->t('Далі натисніть «Створити моє колесо».'),
        $this->t('Повторне натискання не створює друге колесо.'),
      ]),
      $this->item($this->t('Які є сфери?'), [
        $this->t("Здоров'я, Стосунки, Оточення, Покликання."),
        $this->t('Забезпеченість, Самовдосконалення.'),
        $this->t('Яскравість життя, Духовність.'),
      ]),
      $this->item($this->t('Хто ставить оцінку?'), [
        $this->t('Остаточну оцінку від 0 до 10 ставите ви.'),
        $this->t('Орієнтир лише підказує.'),
        $this->t('Він переноситься кнопкою «Взяти орієнтир».'),
      ]),
      $this->item($this->t('Що означає «Не оцінено»?'), [
        $this->t('0 є повноцінною оцінкою.'),
        $this->t('«Не оцінено» означає, що оцінки ще немає.'),
        $this->t('Із цією позначкою зріз не зберігається.'),
      ]),
      $this->item($this->t('Які є відповіді на твердження?'), [
        $this->t('Так, Частково, Ні.'),
        $this->t('Порожня відповідь не входить в орієнтир.'),
        $this->t('Відповідь «Ні» не є завданням.'),
      ]),
      $this->item($this->t('Чим чернетка відрізняється від зрізу?'), [
        $this->t('Чернетка зберігає незавершену роботу.'),
        $this->t('Зріз фіксує дату, оцінки й відповіді.'),
        $this->t('Оцінки й відповіді зрізу змінити не можна.'),
        $this->t('Нотатку можна змінити. Зріз можна видалити.'),
      ]),
      $this->item($this->t('Що доступно без оплати?'), [
        $this->t('На кожну сферу є 10 базових тверджень.'),
        $this->t('Разом їх 80.'),
        $this->t('Ще 10 на сферу відкриває доступ Premium.'),
        $this->t('Історія, порівняння і динаміка доступні завжди.'),
      ]),
      $this->item($this->t('Що дає Premium?'), [
        $this->t('Розширені твердження, рекомендації, цілі.'),
        $this->t('Також є один план на 30 днів.'),
        $this->t('Після завершення доступу свої дані лишаються.'),
        $this->t('Нові розширені дії без доступу закриті.'),
      ]),
      $this->item($this->t('Як працює ознайомлення?'), [
        $this->t('Кнопка «Спробувати Premium 7 днів» з’являється після зрізу.'),
        $this->t('Потрібно, щоб ознайомлення було увімкнене і ще не використане.'),
        $this->t('Картка не потрібна. Списань немає. Один раз на акаунт.'),
        $this->t('Старт треба підтвердити на окремій сторінці.'),
      ]),
      $this->item($this->t('Як влаштована оплата?'), [
        $this->t('Коли оплату увімкнено, є плани на 30 і 365 днів.'),
        $this->t('Перед оплатою видно ціну, строк і дату завершення.'),
        $this->t('Автопродовження немає.'),
        $this->t('Номер картки на цей сайт не передається.'),
        $this->t('Доступ відкривається після підтвердження платежу.'),
        $this->t('Нова оплата під час чинного доступу стартує після його кінця.'),
      ]),
      $this->item($this->t('Що таке рекомендація, ціль і план?'), [
        $this->t('Твердження оцінює стан. Рекомендація є порадою.'),
        $this->t('Ціль є вашим завданням. Її виконання не змінює оцінку.'),
        $this->t('Можна обрати одну або дві сфери фокусу.'),
        $this->t('Одночасно триває лише один план на 30 днів.'),
        $this->t('Вкладка «90 днів» показує цілі зі строком, а не окремий план.'),
        $this->t('Друк відкриває сторінку для друку в браузері.'),
      ]),
      $this->item($this->t('Хто бачить мої оцінки?'), [
        $this->t('Оцінки, відповіді й нотатки бачите лише ви.'),
        $this->t('Адміністратор сайту не бачить їх в інтерфейсі.'),
      ]),
      $this->item($this->t('Як завантажити дані або видалити акаунт?'), [
        $this->t('На сторінці «Мої дані» є JSON і CSV.'),
        $this->t('Видалення прибирає зрізи, відповіді, нотатки й чернетку.'),
        $this->t('Також зникають цілі, плани і прив’язка входу.'),
        $this->t('Замовлення для обліку лишається без зв’язку з акаунтом.'),
      ]),
      $this->item($this->t('Чому інший вхід не відкрив цей акаунт?'), [
        $this->t('Однаковий email не з’єднує акаунти сам.'),
        $this->t('Увійдіть наявним способом і прив’яжіть другий у «Мої дані».'),
        $this->t('Останній спосіб входу відв’язати не можна.'),
      ]),
      $this->item($this->t('Як вимкнути нагадування?'), [
        $this->t('На «Мої дані» є позначка про сервісні нагадування.'),
        $this->t('Лист про початок ознайомлення надсилається і з нею.'),
      ]),
      $this->item($this->t('Де умови і повернення?'), [
        $this->t('Поруч є конфіденційність, оферта і повернення.'),
        $this->t('Оферта і політика повернення ще є заготовками власника.'),
      ]),
    ];
  }

  /**
   * One question with its answer paragraphs.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $question
   *   The question.
   * @param list<\Drupal\Core\StringTranslation\TranslatableMarkup> $answers
   *   Answer paragraphs.
   *
   * @return array{question: \Drupal\Core\StringTranslation\TranslatableMarkup, answers: list<\Drupal\Core\StringTranslation\TranslatableMarkup>}
   *   One FAQ item.
   */
  private function item(TranslatableMarkup $question, array $answers): array {
    return [
      'question' => $question,
      'answers' => $answers,
    ];
  }

}
