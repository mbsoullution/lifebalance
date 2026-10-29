<?php

declare(strict_types=1);

/**
 * @file
 * Environment-based local overrides.
 *
 * Copy this file to settings.local.php. settings.local.php and .env are
 * gitignored. Database credentials are applied only when DRUPAL_DB_HOST is
 * set, so DDEV can keep using settings.ddev.php.
 *
 * Production sets DRUPAL_TRUSTED_HOST to the public hostname. Cookie flags
 * live in settings.php. DRUPAL_COOKIE_SECURE=0 is only for a local HTTP site.
 * MAILER_DSN, for example smtp://user:pass@smtp.example.com:587, is read
 * by settings.php. The password is not stored in config sync.
 *
 * PHP on shared hosting does not see variables exported in an SSH session.
 * Set them in the hosting panel, or assign them in the server copy of this
 * file. That copy is settings.local.php and is never committed.
 */

$db_host = getenv('DRUPAL_DB_HOST');
if (is_string($db_host) && $db_host !== '') {
  $databases['default']['default'] = [
    'database' => getenv('DRUPAL_DB_NAME') ?: 'db',
    'username' => getenv('DRUPAL_DB_USER') ?: 'db',
    'password' => getenv('DRUPAL_DB_PASSWORD') ?: 'db',
    'prefix' => '',
    'host' => $db_host,
    'port' => getenv('DRUPAL_DB_PORT') ?: '3306',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'driver' => 'mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  ];
}

$hash_salt = getenv('HASH_SALT');
if (is_string($hash_salt) && $hash_salt !== '') {
  $settings['hash_salt'] = $hash_salt;
}

// OAuth client secrets stay in the environment. Config sync keeps them empty.
$oauth = [
  'social_auth_google.settings' => [
    'client_id' => 'GOOGLE_CLIENT_ID',
    'client_secret' => 'GOOGLE_CLIENT_SECRET',
  ],
  'social_auth_facebook.settings' => [
    'client_id' => 'FACEBOOK_CLIENT_ID',
    'client_secret' => 'FACEBOOK_CLIENT_SECRET',
  ],
];
foreach ($oauth as $config_name => $keys) {
  foreach ($keys as $config_key => $env_key) {
    $value = getenv($env_key);
    if (is_string($value) && $value !== '') {
      $config[$config_name][$config_key] = $value;
    }
  }
}

// The deploy layout is www/releases/ID/web. Three levels up is www/.
// The directory is absent on DDEV, so this stays unset there.
if (isset($app_root) && is_string($app_root)) {
  $private = dirname($app_root, 3) . '/shared/private';
  if (is_dir($private)) {
    $settings['file_private_path'] = $private;
  }
}
