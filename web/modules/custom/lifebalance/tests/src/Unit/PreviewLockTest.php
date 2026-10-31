<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Seo\PreviewLock;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Preview hostname, htpasswd parsing, and browser-password checks.
 */
#[Group('lifebalance')]
final class PreviewLockTest extends UnitTestCase {

  /**
   * Only the preview hostname is closed. Local hosts stay open.
   */
  public function testHostMatch(): void {
    $this->assertTrue(PreviewLock::isPreviewHost('www.mbprotest.site'));
    $this->assertTrue(PreviewLock::isPreviewHost('mbprotest.site'));
    $this->assertTrue(PreviewLock::isPreviewHost('WWW.MBPROTEST.SITE'));
    $this->assertFalse(PreviewLock::isPreviewHost('lifebalance.ddev.site'));
    $this->assertFalse(PreviewLock::isPreviewHost('evil-mbprotest.site'));
    $this->assertFalse(PreviewLock::isPreviewHost(NULL));
  }

  /**
   * A bcrypt line is accepted. Other hashes and extra text are not.
   */
  public function testParse(): void {
    $hash = password_hash('correct-horse', PASSWORD_BCRYPT);
    $parsed = PreviewLock::parse('preview:' . $hash);
    $this->assertIsArray($parsed);
    $this->assertSame('preview', $parsed['user']);
    $this->assertSame($hash, $parsed['hash']);
    $this->assertNull(PreviewLock::parse('preview:$apr1$salt$hash'));
    $this->assertNull(PreviewLock::parse('preview:' . $hash . ' extra'));
    $this->assertNull(PreviewLock::parse(''));
  }

  /**
   * The matching Basic header is accepted. A wrong password is rejected.
   */
  public function testAllows(): void {
    $hash = password_hash('correct-horse', PASSWORD_BCRYPT);
    $header = 'Basic ' . base64_encode('preview:correct-horse');
    $this->assertTrue(PreviewLock::allows(
      NULL,
      NULL,
      NULL,
      $header,
      'preview',
      $hash,
    ));
    $wrong = 'Basic ' . base64_encode('preview:nope');
    $this->assertFalse(PreviewLock::allows(
      NULL,
      NULL,
      NULL,
      $wrong,
      'preview',
      $hash,
    ));
    $this->assertFalse(PreviewLock::allows(
      NULL,
      NULL,
      NULL,
      NULL,
      'preview',
      $hash,
    ));
    $this->assertTrue(PreviewLock::allows(
      'preview',
      NULL,
      NULL,
      NULL,
      'preview',
      $hash,
    ));
    $this->assertTrue(PreviewLock::allows(
      NULL,
      'preview',
      'correct-horse',
      NULL,
      'preview',
      $hash,
    ));
  }

  /**
   * The scaffold files keep the preview host out of the index.
   */
  public function testScaffoldClosesThePreviewHost(): void {
    $root = dirname(__DIR__, 7);
    $robots = (string) file_get_contents($root . '/web/robots-deny.txt');
    $this->assertStringContainsString('Disallow: /', $robots);
    $appendPath = $root . '/assets/scaffold/htaccess-preview.append';
    $append = (string) file_get_contents($appendPath);
    $this->assertStringContainsString('mbprotest\\.site', $append);
    $this->assertStringContainsString('X-Robots-Tag', $append);
    $this->assertStringContainsString('robots-deny.txt', $append);
    $composer = (string) file_get_contents($root . '/composer.json');
    $this->assertStringContainsString('htaccess-preview.append', $composer);
  }

}
