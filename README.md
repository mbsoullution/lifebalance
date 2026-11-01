# LifeBalance deployment

LifeBalance is a Drupal 11 site. The Composer project root is this directory. The web server document root is `web/`. Production for this pipeline is [https://www.mbprotest.site](https://www.mbprotest.site) on Ukraine.com.ua shared hosting.

Runtime operations (mail, cron, encrypted backups) stay in [docs/DEPLOY.md](docs/DEPLOY.md). This file is how code gets there.

The workflow does not deploy on push. The default run is a dry run. Nothing in this repository connects to the server.

## What is deployed

GitHub Actions checks out `main`, installs PHP 8.3 and Node 22, and runs `composer build`.

That command installs production PHP dependencies and rebuilds the theme. It produces `vendor/`, Drupal core, contributed projects, and the scaffold files under `web/` (`index.php`, `.htaccess`). Dev packages such as `drupal/devel` are not installed. `node_modules` stays off the server.

The same scripts are the local entry points:

```bash
ddev start
ddev composer dev
ddev drush deploy
```

`composer dev` installs PHP dependencies, including development tools, and runs the theme build. `composer lint` and `composer test` run the same checks as CI. On the server, after the files are published, `drush deploy` imports configuration. It is separate because it needs the production database.

`drush deploy` on the server then imports `config/sync`, runs database updates, rebuilds cache, and runs the project deploy hooks. Those hooks import the criteria catalog and the recommendation catalog. They do not delete snapshots, accounts, or uploaded files.

## Server layout

Ukraine.com.ua keeps each site under the account home. The panel's site directory is `~/mbprotest.site/www` (confirm the exact path under **Налаштування сайту → Кореневий каталог**). The panel can point the document root only at a subdirectory of that `www` directory. There is no root access.

```text
/home/USER/mbprotest.site/www/
  releases/
    20261029T220000Z-abcdef123456/   # one immutable build
      vendor/
      config/sync/
      web/                           # Drupal document root for this build
  shared/                            # never replaced by rsync
    settings.local.php               # mode 600, server only
    files/                           # public uploads, including avatars
    private/                         # private files, outside the document root
    backups/
    previous_release                 # written by a successful deploy
    layout-reviewed                  # empty marker, created by hand
  current -> releases/<id>           # switched only after a successful publish
```

Set the panel document root to `current/web` after the first publish. Set `open_basedir` one level above that root so PHP can read `vendor/`, `config/`, and `shared/`. The hosting wiki requires this when the site runs from a subdirectory.

After the second publish, confirm the panel still shows `current/web` and has not stored a resolved `releases/<old-id>/web`. If it stored the resolved path, later publishes will not change the live site.

## What the pipeline refuses to touch

- `shared/files/` and `shared/private/` are linked into each release. rsync does not use `--delete` and never uses `shared/` as its destination.
- `settings.local.php`, `settings.ddev.php`, and `.env` are excluded from the build. The release links the existing `shared/settings.local.php`. A real file already inside the release stops the publish.
- The database is not dumped, imported, or dropped. A failed `drush deploy` does not move `current`. Rollback moves only that symlink. Schema updates already written to the database stay there.
- The workflow does not run `site:install`, does not import a DDEV database, and does not delete unfamiliar files already in `www/`.

If `www/` contains anything other than `releases/`, `shared/`, and `current`, publish stops until you create the empty marker `shared/layout-reviewed`. Create that marker only after you have read the plan log.

## Secrets

Create a GitHub environment named `production` and store the secrets there, not in the repository.

| Secret | Value |
|---|---|
| `SSH_HOST` | SSH host from the panel, for example `example.ftp.tools` |
| `SSH_PORT` | SSH port from the panel |
| `SSH_USER` | SSH user from the panel |
| `SSH_PRIVATE_KEY` | Private key, including the BEGIN and END lines |
| `SSH_KNOWN_HOSTS` | One or more `known_hosts` lines for that host and port |
| `DEPLOY_ROOT` | Absolute path ending in `/www`, such as `/home/USER/mbprotest.site/www` |
| `PHP_BIN` | Optional. Leave empty to use `php`. Set it only when the default CLI is older than 8.3 |

Generate the key on your machine, outside the repository:

```bash
ssh-keygen -t ed25519 -f "$HOME/lifebalance-deploy" -C "github-actions-deploy-mbprotest"
```

Install `lifebalance-deploy.pub` in the hosting panel's SSH authorized keys. Put the private file in `SSH_PRIVATE_KEY`. Do not commit either file. `.gitignore` ignores `*.deploy_key` and `deploy_keys/`.

For `SSH_KNOWN_HOSTS`, connect once and compare the fingerprint with the panel before trusting it:

```bash
ssh-keyscan -p PORT HOST
```

Paste the resulting lines into the secret. The workflow sets `StrictHostKeyChecking=yes` and does not scan keys itself.

`SSH_PRIVATE_KEY` must be the private half of the same key, including the BEGIN and END lines, with an empty passphrase. The workflow offers only that key. If the server replies `Too many authentication failures` or `Permission denied (publickey)`, the public key is missing from `~/.ssh/authorized_keys` or the secret is a different key. The job prints the key fingerprint and does not print the key.

## One-time server setup

Do this by SSH before the first real deploy. Do not use **Установка CMS**. Do not upload the DDEV database.

1. In the panel, select PHP 8.3 and create an empty MariaDB database.
2. Confirm `php -v` over SSH is 8.3 or newer. If it is not, find the 8.3 binary the panel shows and store it as `PHP_BIN`.
3. Create the shared directories:

```bash
mkdir -p "$HOME/mbprotest.site/www/shared/files" \
  "$HOME/mbprotest.site/www/shared/private" \
  "$HOME/mbprotest.site/www/shared/backups"
chmod 750 "$HOME/mbprotest.site/www/shared/files" \
  "$HOME/mbprotest.site/www/shared/private"
```

4. Copy `web/sites/default/example.settings.local.php` to `shared/settings.local.php` and replace the environment lookups with the panel values. PHP on this host does not see variables exported in an SSH session. `settings.php` reads the trusted host before it includes the local file, so set the pattern directly:

```php
$databases['default']['default'] = [
  'database' => 'PANEL_DATABASE',
  'username' => 'PANEL_USER',
  'password' => 'PANEL_PASSWORD',
  'prefix' => '',
  'host' => 'PANEL_HOST',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];
$settings['hash_salt'] = 'LONG_RANDOM_VALUE';
$settings['trusted_host_patterns'] = [
  '^www\\.mbprotest\\.site$',
];
```

`www.mbprotest.site` is the only trusted host. Redirect the apex hostname to it in the panel. Keep OAuth secrets, `MAILER_DSN`, and `MONOBANK_TOKEN` in this same server file or in panel PHP variables. They do not belong in Git or `config/sync`.

5. `chmod 600` the local settings file.
6. Create the browser password before the first real deploy. This login is separate from Drupal accounts. The site stays closed to visitors and to search engines until this file exists. Choose the password yourself and do not commit the file:

```bash
php -r 'echo "preview:" . password_hash(trim(fgets(STDIN)), PASSWORD_BCRYPT), "\n";' \
  > "$HOME/mbprotest.site/www/shared/.htpasswd"
chmod 644 "$HOME/mbprotest.site/www/shared/.htpasswd"
```

Type the password and press Enter. The file must be one line, `preview:` plus a bcrypt hash (`$2y$`). Apache reads this file itself, so mode 644 is required. `settings.local.php` stays mode 600. Publish refuses to switch `current` without it, then adds HTTP basic auth to that release. `www.mbprotest.site` also sends `noindex` and a `robots.txt` that disallows everything. Local DDEV has no password file, so it stays open and indexable.

## Run the pipeline

The workflow file is `.github/workflows/deploy.yml`. It runs only from `main`. This clone's published branch is still `master`, and GitHub currently has the initial import. Commit and push the application, including this workflow, to `main` before the first plan. A deploy cannot see uncommitted work.

1. Actions → Deploy → Run workflow.
2. Leave the action as `plan` and leave confirm empty.
3. Read the log. It shows PHP, the top-level names in `www/`, and which shared paths are present or missing. It does not print `settings.local.php`.
4. If the log lists unexpected names, review them. Then, and only then:

```bash
touch /home/USER/mbprotest.site/www/shared/layout-reviewed
```

5. Run the workflow again with action `deploy` and confirm `deploy-www.mbprotest.site`.

The deploy copies the build into `releases/<id>/`, links shared settings and public files, and points `current` at that release. If Drupal is already installed, it enables maintenance mode, runs `drush deploy -y` from the new release, and only then moves `current`. Maintenance mode is turned off afterwards. If `drush deploy` fails, `current` stays on the previous release.

The first deploy skips `drush deploy` when the database is not installed. It still publishes `current`. Install once over SSH, from that release, and then run `deploy` again:

```bash
cd /home/USER/mbprotest.site/www/current
php vendor/bin/drush --root=web site:install standard --existing-config
```

Use the same PHP binary as `PHP_BIN` when that secret is set.

Drush prompts for the first account. Do not add `-y` with a password stored in the shell history if you can avoid it. After install, set the panel document root to `current/web`, then run `deploy` once more so the catalog import hooks run.

Cron stays a panel or crontab entry, every 15 minutes. `automated_cron` stays off.

```bash
*/15 * * * * cd /home/USER/mbprotest.site/www/current && php vendor/bin/drush --root=web cron
```

## Rollback

Run the workflow with action `rollback` and the same confirmation phrase. It points `current` at the release id stored in `shared/previous_release`. It does not delete releases, uploads, or database rows. If `drush deploy` changed the schema, switching the code back does not reverse that schema.

Old `releases/<id>` directories are left on disk. Remove one by hand only after `readlink current` shows a different id.

## Failure

A failed job writes a short note on the GitHub job summary. Shared data is not the rsync destination. The script does not restore a database backup; backups remain the procedure in [docs/DEPLOY.md](docs/DEPLOY.md).
