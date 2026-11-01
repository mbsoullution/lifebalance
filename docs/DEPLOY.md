# Розгортання

Конвеєр GitHub Actions для `www.mbprotest.site` описано в [README.md](../README.md). Він запускається лише вручну і за замовчуванням нічого не записує на сервер. Цей файл лишається інструкцією для Composer, Drush, пошти, cron і бекапу.

Production ставиться без dev-залежностей. Каталог критеріїв імпортується під час `drush deploy`. Секрети і пароль бекапу не потрапляють у Git.

## Закритий перегляд

`www.mbprotest.site` не індексується: відповіді несуть `X-Robots-Tag: noindex, nofollow, noarchive`, а `robots.txt` закриває весь сайт. Перед першим справжнім деплоєм на сервері має лежати `www/shared/.htpasswd` (один рядок `preview:` і bcrypt-хеш, права `644`, щоб Apache міг його прочитати). Публікація без цього файла не перемикає `current`. Після неї браузер питає логін `preview` і пароль, перш ніж показати будь-яку сторінку. Це окремий вхід, не обліковий запис Drupal. Локальний DDEV цього файла не має, тож лишається відкритим.

## Встановлення

```bash
composer build
```

`drupal/devel` стоїть у `require-dev`, тому в цьому наборі його немає. `drupal/config_split` лишається. Split `dev` у `config/sync` вимкнений (`status: false`). У ньому `dblog`, `devel` і `views_ui`. На production ці модулі не вмикаються. `views` увімкнений: ним користується лише адмінсписок рекомендацій.

Скопіюйте `web/sites/default/example.settings.local.php` у `settings.local.php` (файл у `.gitignore`) і задайте змінні оточення:

- `DRUPAL_TRUSTED_HOST` — публічний hostname, не регулярний вираз. Без цієї змінної поза DDEV список довірених хостів не задається.
- `HASH_SALT`
- `DRUPAL_DB_HOST`, `DRUPAL_DB_NAME`, `DRUPAL_DB_USER`, `DRUPAL_DB_PASSWORD`
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` — лише якщо соцлогін увімкнений
- `MAILER_DSN` — URL пошти для core Symfony Mailer, наприклад `smtp://user:pass@smtp.example.com:587`. `settings.php` розбирає його в `system.mail:mailer_dsn`. Пароль у конфіг не пишеться. Без змінної лишається `sendmail://default` з експорту. На DDEV без `MAILER_DSN` пошта йде на Mailpit (`smtp://localhost:1025`).
- `BACKUP_PASSPHRASE` — пароль шифрування дампу, окремо від репозиторію

`settings.php` виставляє cookie `HttpOnly`, `SameSite=Lax` і `Secure`. Для локального HTTP можна задати `DRUPAL_COOKIE_SECURE=0`. На DDEV шаблон хоста звужується до `lifebalance.ddev.site`, а split `dev` вмикається перевизначенням у `settings.php`.

## Пошта

Листи відновлення пароля надсилає core (`interface.default: symfony_mailer`). Домен у `From` має збігатися з доменом, для якого налаштовані DNS-записи:

- SPF дозволяє сервер, який реально відправляє лист.
- DKIM підписує лист ключем цього домену.
- DMARC (`p=quarantine` або суворіше після перевірки) спирається на SPF і DKIM.

Секретів DNS у репозиторії немає. Перед публічним запуском один лист відновлення перевіряють у поштовій скриньці, зокрема що він не потрапив у спам. На DDEV той самий лист видно в Mailpit.

## Системний cron

Модуль `automated_cron` не вмикається. На сервері окреме завдання кожні 15 хвилин:

```bash
*/15 * * * * cd /var/www/lifebalance && drush cron
```

Шлях замініть на каталог проєкту. `drush cron` знімає чергу, перевіряє оновлення і чистить тимчасові дані.

## Оновлення

```bash
drush deploy
```

Команда виконує оновлення схеми, імпорт конфігурації, скидання кешу і deploy-хуки. Хук `lifebalance_deploy_import_criteria` читає `web/modules/custom/lifebalance/data/criteria.v1.yml`. Тексти критеріїв змінюються лише в цьому файлі або в адмінці, не в коді.

## Бекап

Дамп бази шифрується AES-256-CBC. Файли лежать поза docroot, наприклад у `/var/backups/lifebalance`. Пароль береться зі змінної `BACKUP_PASSPHRASE`.

```bash
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST=/var/backups/lifebalance
mkdir -p "$DEST"
mysqldump --single-transaction --skip-comments "$DRUPAL_DB_NAME" \
  | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE \
    -out "$DEST/lifebalance-$STAMP.sql.enc"
find "$DEST" -name 'lifebalance-*.sql.enc' -mtime +30 -delete
```

`find` видаляє дампи старші за 30 діб. Відновлення поверх порожньої або зупиненої бази:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE \
  -in "$DEST/lifebalance-$STAMP.sql.enc" \
  | mysql "$DRUPAL_DB_NAME"
```

## Перевірене відновлення

2026-10-11 на локальній базі DDEV:

1. `mysqldump` живої бази `db` зашифровано `openssl enc -aes-256-cbc -pbkdf2`. Початок файла не містив SQL.
2. Файл розшифровано і завантажено в окрему базу `lb_restore_check`. Живу базу не перезаписували.
3. Збіглися: 70 таблиць, 0 рядків `lifebalance_snapshot`, 2 користувачі, байт-у-байт рядок `config` для `lifebalance.settings`.
4. `lb_restore_check` і файли дампа видалено.
5. Окремо перевірено ротацію: файл із датою 31 добу тому команда `find -mtime +30 -delete` прибрала, свіжий файл лишився.

Після перевірки `premium_enabled` лишився вимкненим, зрізів 0.
