<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Production config leaves personal HTTP APIs and dev modules off.
 */
#[Group('lifebalance')]
final class SiteExtensionTest extends UnitTestCase {

  /**
   * JSON:API, REST, and Commerce stay out of the exported site.
   */
  public function testPersonalHttpApisStayDisabled(): void {
    $modules = $this->extensionModules('config/sync/core.extension.yml');
    $forbidden = [
      'jsonapi',
      'rest',
      'commerce',
      'views_ui',
      'dblog',
      'devel',
      'automated_cron',
    ];
    foreach ($forbidden as $name) {
      $this->assertArrayNotHasKey($name, $modules);
    }
    $this->assertArrayHasKey('config_split', $modules);
    $this->assertArrayHasKey('lifebalance', $modules);
    $this->assertArrayHasKey('lifebalance_plans', $modules);
    $this->assertArrayHasKey('views', $modules);
    $this->assertArrayHasKey('seckit', $modules);
    $this->assertArrayHasKey('metatag', $modules);
    $this->assertArrayHasKey('token', $modules);
  }

  /**
   * The dev split is inactive and holds dblog, devel, and Views UI.
   *
   * Views itself stays enabled: the recommendation library is a non-personal
   * admin list.
   */
  public function testDevSplitStaysInactive(): void {
    $split = $this->readYaml('config/sync/config_split.config_split.dev.yml');
    $this->assertFalse($split['status']);
    $this->assertSame('../config/dev', $split['folder']);
    $listed = $split['module'];
    $this->assertIsArray($listed);
    $keys = array_keys($listed);
    sort($keys);
    $this->assertSame(['dblog', 'devel', 'views_ui'], $keys);
    $root = $this->root();
    $this->assertFileExists($root . '/config/dev/dblog.settings.yml');
    $this->assertFileDoesNotExist($root . '/config/dev/views.settings.yml');
    $this->assertFileDoesNotExist($root . '/config/sync/dblog.settings.yml');
    $this->assertFileExists($root . '/config/sync/views.settings.yml');
    $mail = $this->readYaml('config/sync/system.mail.yml');
    $interface = $mail['interface'];
    $this->assertIsArray($interface);
    $this->assertSame('symfony_mailer', $interface['default']);
    $dsn = $mail['mailer_dsn'];
    $this->assertIsArray($dsn);
    $this->assertNull($dsn['password']);
  }

  /**
   * Snapshots have no Views handler and no admin collection route.
   */
  public function testSnapshotsHaveNoViewsData(): void {
    $module = dirname(__DIR__, 3);
    $source = (string) file_get_contents($module . '/src/Entity/Snapshot.php');
    $this->assertStringNotContainsString('views_data', $source);
    $this->assertStringNotContainsString('list_builder', $source);
    $routes = (string) file_get_contents($module . '/lifebalance.routing.yml');
    $this->assertStringNotContainsString('views_data', $routes);
    $this->assertStringNotContainsString(
      '/admin/content/lifebalance_snapshot',
      $routes,
    );
  }

  /**
   * Module list from an exported core.extension file.
   *
   * @return array<string, mixed>
   *   Enabled projects keyed by machine name.
   */
  private function extensionModules(string $relative): array {
    $extension = $this->readYaml($relative);
    $modules = $extension['module'];
    $this->assertIsArray($modules);
    return $modules;
  }

  /**
   * Decoded YAML mapping from the project root.
   *
   * @return array<string, mixed>
   *   Top-level mapping.
   */
  private function readYaml(string $relative): array {
    $path = $this->root() . '/' . $relative;
    $this->assertFileExists($path);
    $decoded = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($decoded);
    return $decoded;
  }

  /**
   * Absolute project root.
   */
  private function root(): string {
    return dirname(__DIR__, 7);
  }

}
