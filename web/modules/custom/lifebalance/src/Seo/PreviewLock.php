<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Seo;

/**
 * Browser password and noindex rules for the preview hostname.
 *
 * The password file lives only on the server, next to shared settings.
 * DDEV has no such file, so local requests stay open.
 */
final class PreviewLock {

  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * Whether this hostname is the closed preview site.
   */
  public static function isPreviewHost(?string $host): bool {
    if (!is_string($host) || $host === '') {
      return FALSE;
    }
    return (bool) preg_match('/^(www\.)?mbprotest\.site$/i', $host);
  }

  /**
   * Absolute path of the server password file for this release layout.
   */
  public function passwordFile(): string {
    return dirname($this->appRoot, 3) . '/shared/.htpasswd';
  }

  /**
   * The single bcrypt user from the password file, when the file is usable.
   *
   * @return array{user: string, hash: string}|null
   *   Username and hash, or NULL when the preview site has no lock file.
   */
  public function credentials(): ?array {
    $path = $this->passwordFile();
    if (!is_file($path) || is_link($path)) {
      return NULL;
    }
    $size = filesize($path);
    if ($size === FALSE || $size < 20 || $size > 4096) {
      return NULL;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
      return NULL;
    }
    $line = strtok($raw, "\r\n");
    if (!is_string($line)) {
      return NULL;
    }
    return self::parse($line);
  }

  /**
   * Parses one htpasswd line.
   *
   * @return array{user: string, hash: string}|null
   *   Username and bcrypt hash, or NULL when the line is not usable.
   */
  public static function parse(string $line): ?array {
    $matched = preg_match(
      '/^([A-Za-z0-9._-]{1,64}):(\$2[aby]\$\S+)$/',
      $line,
      $matches,
    );
    if ($matched !== 1) {
      return NULL;
    }
    return [
      'user' => $matches[1],
      'hash' => $matches[2],
    ];
  }

  /**
   * Whether Apache or the Authorization header already presented the user.
   */
  public static function allows(
    ?string $remoteUser,
    ?string $phpUser,
    ?string $phpPassword,
    ?string $authorization,
    string $user,
    string $hash,
  ): bool {
    $remoteMatches = is_string($remoteUser)
      && $remoteUser !== ''
      && hash_equals($user, $remoteUser);
    if ($remoteMatches) {
      return TRUE;
    }
    if (is_string($phpUser) && is_string($phpPassword) && $phpUser !== '') {
      return hash_equals($user, $phpUser)
        && password_verify($phpPassword, $hash);
    }
    if (!is_string($authorization)) {
      return FALSE;
    }
    if (!str_starts_with($authorization, 'Basic ')) {
      return FALSE;
    }
    $decoded = base64_decode(substr($authorization, 6), TRUE);
    if (!is_string($decoded) || !str_contains($decoded, ':')) {
      return FALSE;
    }
    [$name, $pass] = explode(':', $decoded, 2);
    return hash_equals($user, $name) && password_verify($pass, $hash);
  }

}
