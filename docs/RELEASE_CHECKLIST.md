# Чекліст релізу MVP

Перед релізом мають бути зеленими ті самі команди, що в `.github/workflows/ci.yml`. PHPStan підключає `phpstan-deprecation-rules`, тож застарілі виклики валять аналіз.

```bash
composer install --no-interaction --no-progress
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpunit -c phpunit.xml
```

У CI немає бази і Chrome. `WheelJavascriptTest` пропускається, доки не задано `MINK_DRIVER_ARGS_WEBDRIVER`. На DDEV повний набір гоняється тими самими командами через `ddev exec`.

## Критерії MVP

| Критерій | Покриття |
|---|---|
| Соцлогін і свій кабінет | `SocialAuthKernelTest`, `AccountDataTest` |
| Ізоляція даних | `SnapshotKernelTest`, `DraftApiTest`, `HistoryTest`, `CompareTrendTest`, `AccountExportTest` |
| 8 секторів, оцінка 0–10, нуль є оцінкою | `SectorKernelTest`, `SnapshotKernelTest::testServerRecalculatesGuideAndKeepsZero` |
| Оновлення без перезавантаження | `WheelJavascriptTest` (потрібен браузер). Формула ще в `js/guide.test.js` |
| Зріз із датою, власником і значеннями | `SnapshotKernelTest`, `WheelFormTest` |
| Чернетка не змінює історію | `DraftKernelTest::testDraftDoesNotChangeSnapshots` |
| Порівняння і динаміка | `CompareTrendTest`, `SnapshotDeltaTest`, `TrendGeometryTest` |
| Стани 0 і 1 зрізу | `HistoryTest`, `CompareTrendTest` |
| Мобільні і клавіатура | Ручна перевірка коліс, опитувальника і радара на вузькому екрані |
| Автотести | Цей список і CI |
| Історичне формулювання критерію | `HistoryKernelTest::testHistoryKeepsTheAnsweredRevision` |
| Premium лише на сервері, свої відповіді лишаються видимими | `PremiumAdminTest`, `EntitlementChecker` без `hasRole` |

## Безпека і випуск

- [ ] `docs/SECURITY_CHECKLIST.md`: автоматичні пункти зелені, ручні переглянуті.
- [ ] `SiteExtensionTest`: jsonapi, rest, views_ui, commerce, dblog, devel і automated_cron відсутні в `config/sync`. `views` є, бо ним користується список рекомендацій. seckit, metatag і token присутні.
- [ ] `DRUPAL_TRUSTED_HOST` задано на production. Cookie лишаються `Secure`, `HttpOnly`, `SameSite=Lax`.
- [ ] Секрети OAuth, `MAILER_DSN` і `BACKUP_PASSPHRASE` лише в оточенні. У Git і `config/sync` секретів немає.
- [ ] SPF, DKIM і DMARC домену відправника описані в `docs/DEPLOY.md` і виставлені в DNS.
- [ ] Лист відновлення пароля доходить. На DDEV його видно в Mailpit. У бойовій скриньці він не в спамі.
- [ ] Заголовки CSP, HSTS, `X-Frame-Options: DENY` і Referrer-Policy є на публічній сторінці. Посилання входу через Google і Facebook лишаються на головній.
- [ ] `composer install --no-dev`, потім `drush deploy` (разом з імпортом каталогу критеріїв).
- [ ] Split `dev` на production вимкнений. `views_ui` туди не потрапляє.
- [ ] Crontab `drush cron` кожні 15 хвилин. `automated_cron` вимкнений.
- [ ] Свіжий зашифрований бекап і ротація 30 діб. Відновлення вже виконано 2026-10-11, див. `docs/DEPLOY.md`.
- [ ] У логах немає оцінок, нотаток і текстів відповідей.
- [ ] Щоденна робота йде не з облікового запису 1. У нього є всі дозволи ядра.
- [ ] `premium_enabled` увімкнено лише свідомим рішенням. Платежів у MVP немає.

## Запуск продажів

Код оплати зібраний. Продажі не вмикаються, доки закриті пункти нижче. Звіт розділів 11 і 22.4 аудиту — `docs/SALES_READINESS.md`.

- [ ] Реквізити продавця: назва, податковий код, адреса, контакт. Їх ще немає в `/terms` і `/refunds`.
- [ ] Текст публічної оферти від власника замість заготовки на `/terms`.
- [ ] Політика повернення від власника: строк звернення, спосіб і реквізити. Заготовка на `/refunds` описує лише поведінку сайту.
- [ ] Рішення бухгалтера щодо ПРРО/фіскалізації. Інтеграції фіскалізації в цьому плані немає.
- [ ] Ціни `premium_30` і `premium_365` затверджені. Зараз у конфігурації гіпотеза концепції: 14900 і 119000 копійок.
- [ ] `MONOBANK_TOKEN` лише в оточенні. У `config/sync` токена немає.
- [ ] `premium_enabled` увімкнено свідомо, після оферти.
- [ ] Тестова оплата мінімальної суми на бойовому сайті і повернення в кабінеті провайдера. На локальному DDEV цього платежу не було: токена мерчанта немає, публічної адреси для вебхука немає.
- [ ] Після бойового платежу рівно один доступ, після повернення доступ завершено, зрізи й цілі на місці.

