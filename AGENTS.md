# AGENTS.md — LifeBalance

Drupal 11 проєкт. Перед роботою прочитай:

1. `docs/AUDIT_v0.7.md` — архітектура й рішення (пріоритет над концепцією; усередині діє розділ з більшим номером).
2. `docs/ROADMAP.md` — етапи 0–4 і поточний крок. Виконуй **лише його**.
3. `.cursor/rules/*.mdc` — обов'язкові правила (00 проєкт, 10 Drupal, 20 безпека, 30 фронтенд, 40 тести, 50 billing, 60 plans).
4. `docs/concept/CONCEPT_v0.6.md` — продуктова концепція (контекст).

Дані:
- `web/modules/custom/lifebalance/data/criteria.v1.yml` — 160 критеріїв.
- `docs/content/recommendations.example.yml` — формат бібліотеки рекомендацій (етап 3).
- `docs/content/landing.uk.md` — текст головної (крок 13).
- `docs/content/faq.uk.md` — часті запитання; розділи з етапом лишаються прихованими.
- `docs/content/privacy.uk.md`, `terms.uk.md`, `refunds.uk.md` — правові сторінки. Поля `[...]` заповнює власник, до цього текст не публікується.

Тексти не переписуй у коді.

Команди (DDEV):

```
ddev composer install
ddev drush deploy
ddev drush lifebalance:import-criteria
ddev exec vendor/bin/phpcs
ddev exec vendor/bin/phpstan analyse
ddev exec vendor/bin/phpunit -c phpunit.xml
```

Якщо задача суперечить правилам або неоднозначна, зупинись і спитай.
