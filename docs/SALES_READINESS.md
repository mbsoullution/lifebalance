# Готовність до продажів

Звіт кроку 24 за розділами 11 і 22.4 аудиту v0.6. Пункт закритий, коли його покриває код і тест. Відкриті пункти не блокують збірку, але блокують увімкнення продажів.

Бойової оплати й повернення на проді не було.

## Розділ 11

| Пункт | Стан | Де перевірено |
|---|---|---|
| Зріз і ціль: доступ лише власнику. Адмін-дозвіл зрізів нікому не видається. | Закрито | `SnapshotKernelTest::testOnlyTheOwnerCanViewOrDelete`, `GoalKernelTest`, `PlanFormTest` |
| Немає `views_data` для зрізів, цілей, entitlement і замовлень. JSON:API, REST, Commerce вимкнені. | Закрито. `views_data` є лише в бібліотеки рекомендацій. | `SiteExtensionTest` |
| UUID в URL зрізу й замовлення. | Закрито | `HistoryTest`, `BillingAdminTest` |
| Ролі `site_manager`, `support_manager`, `content_editor`, `finance_manager` без доступу до оцінок, відповідей, нотаток і цілей. | Закрито | `AdminUserListTest`, `AdminPremiumTest`, `BillingKernelTest::testFinanceRolePermissions` |
| Обліковий запис 1 не для щоденної роботи. | Відкрито як правило релізу. У нього всі дозволи ядра. | `docs/RELEASE_CHECKLIST.md` |
| Premium лише через `EntitlementChecker`. `hasRole()` у власних модулях немає. | Закрито | `EntitlementKernelTest`, пошук `hasRole` |
| Серверна перевірка Premium у формі, чернетці, опитувальнику, рушії, цілях і checkout. | Закрито | `QuestionnaireFormTest`, `DraftKernelTest::testRejection`, `RecommendationKernelTest`, `GoalKernelTest::testPremiumIsRequiredAndLimitIsTen`, `BillingHttpTest` |
| Кеш Premium: контекст `user`, тег entitlement, max-age до межі. | Закрито | `EntitlementKernelTest::testCacheExpiresAtTheBoundary` |
| Ручний доступ: Form API, причина, ревізія. Ревізії entitlement не видаляються. | Закрито | `AdminPremiumTest` |
| Зміни стану через Form API або CSRF. Вебхук без токена форми, із перевіркою підпису. | Закрито | `DraftApiTest`, `BillingHttpTest` |
| `guide` рахує сервер. | Закрито | `SnapshotKernelTest::testServerRecalculatesGuideAndKeepsZero` |
| Flood: зріз 50/добу, чернетка 120/хв, checkout 10/год. | Закрито | `WheelFormTest`, `DraftKernelTest`, `BillingHttpTest` |
| Персональні й billing-сторінки: `Cache-Control: private, no-store`. | Закрито | `PrivateCacheSubscriber` обох модулів, `BillingHttpTest`, `BillingAdminTest` |
| CSP, HSTS, frame-ancestors. Хост сторінки оплати лише в `form-action` і `navigate-to`. | Закрито в коді. Seckit 2.0.3 ці директиви не зберігає, їх додає `PaymentPageCspSubscriber`. | `ReleaseHardeningTest`, `PaymentCspTest` |
| Експорт: зрізи, відповіді, нотатки, чернетка, цілі. | Частково. Історії Premium і замовлень в експорті немає. Технічні поля провайдера туди не потрапляють, бо рядка замовлення немає взагалі. | `AccountExportTest` |
| Видалення акаунта прибирає зрізи, чернетку, цілі, social auth і entitlement. Замовлення лишається, `uid` стає порожнім. | Закрито | `AccountExportTest`, `BillingKernelTest::testAccountDeletionKeepsOrder` |
| У логах немає оцінок, нотаток, токена, тіла вебхука і номера картки. | Закрито переглядом викликів логера billing і entitlement. Окремого автоматичного grep у CI немає. | `BillingKernelTest::testEventRowOmitsBody`, `MonobankGatewayKernelTest` |
| Privacy notice: самооцінка не є діагностикою; платежі; бекапи; технічний доступ. | Частково. Розділ про платежі є. Строк зберігання замовлень і бекапів позначений «TODO для власника». | `AccountExportTest` |
| Секрети OAuth і `MONOBANK_TOKEN` лише в оточенні. | Закрито для токена оплати. OAuth — ручна перевірка порожніх секретів у sync. | `BillingKernelTest::testConfigOmitsProviderMaterial`, `settings.php` |

## Розділ 22.4

| Загроза | Стан | Тест |
|---|---|---|
| Підроблений вебхук | Закрито. Немає режиму без підпису. | `BillingHttpTest` (401), `MonobankGatewayKernelTest::testInvalidSignatureRefreshesOnce` |
| Повтор тієї самої події | Закрито. Другий вебхук не створює другий доступ. | `BillingKernelTest::testPaidNoticeGrantsOnce`, `BillingHttpTest` |
| Старіша подія після новішої | Закрито | `BillingKernelTest::testOlderPaidAfterRefundIsIgnored` |
| Гонка вебхука і звірки | Закрито. Lock, транзакція, один `order_id`. | `BillingKernelTest::testWebhookAndReconcileGrantOnce` |
| Підміна суми | Закрито. Розбіжність переводить очікування в `failed`, суми в журнал не пишуться. | `BillingKernelTest::testAmountMismatchFails` |
| Доступ зі сторінки повернення | Закрито | `BillingHttpTest` |
| Ручне надання без права | Закрито | `AdminPremiumTest` |
| Втрачений вебхук | Закрито. Cron викликає звірку у вікні 5 хвилин — 7 днів. | `BillingKernelTest::testCronClosesLostWebhook` |
| Повернення і спір | Закрито. Доступ знімається, історія лишається. Часткове повернення доступ не знімає, доки фінансист не вирішить. | `BillingKernelTest::testRefundKeepsHistory`, `BillingAdminTest` |
| Кеш billing-сторінок | Закрито | `BillingHttpTest`, `BillingAdminTest` |
| Витік токена | Закрито. Токен не в конфігурації й не в тексті винятку. | `BillingKernelTest::testConfigOmitsProviderMaterial`, `MonobankGatewayKernelTest::testTransportErrorHidesTheRequest` |
| CSRF checkout | Закрито. Форма Drupal, flood 10/год. | `BillingHttpTest` |
| Дані картки | Закрито. Картка лишається на `https://pay.mbnk.biz`. У подію не потрапляє `maskedPan`. | `MonobankGatewayKernelTest::testStatusNoticeDropsCardData` |

## Розділ 25

| # | Стан |
|---|---|
| 1–6 | Закриті кроками 12–15. |
| 7 | Оплата дає доступ один раз. `pending` і `failed` доступу не дають. `BillingKernelTest`, `BillingHttpTest`. |
| 8 | Повтор, підробка, повернення і втрачений callback покриті тестами з таблиці 22.4. |
| 9 | Після оплати Premium відкриває критерії й рекомендації. Після завершення зрізи й цілі лишаються. |
| 10 | Критерій, рекомендація і ціль — різні сутності. |
| 11 | Наставників немає. Ролі фінансів і підтримки зріз не відкривають. |
| 12 | Виконання цілі не змінює оцінку. |
| 13 | Окремого проходу телефона для checkout і цілей у цьому кроці не було. |

## Крок 26

| Пункт | Стан |
|---|---|
| `/terms` і `/refunds` | Відкрито. Це заготовки власника: реквізити, строк звернення і спосіб повернення ще не написані. |
| Розділ про платежі в `/privacy` | Закрито в коді. Строк зберігання замовлень лишається позначкою для власника. |
| `/premium`: 149 і 1190, «30 днів» і «365 днів», основа порівняння, без автопродовження | Закрито в коді. Сторінка відкривається лише коли `premium_enabled` увімкнено. Зараз вимкнено. |
| Кнопки оплати | Лише плани `premium_30` і `premium_365`. Інших продуктів на сторінці немає. |
| Домен сторінки оплати в CSP | Закрито. `https://pay.mbnk.biz` лише в `form-action` і `navigate-to`. |
| Бойова оплата і повернення | Відкрито. Токена мерчанта і публічної адреси вебхука немає. |

Відкрите для запуску продажів зібране в `docs/RELEASE_CHECKLIST.md`, розділ «Запуск продажів».
