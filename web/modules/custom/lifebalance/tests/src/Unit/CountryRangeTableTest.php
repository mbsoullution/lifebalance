<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Unit;

use Drupal\lifebalance\Service\CountryRangeTable;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shipped address table names only Ukraine and Russia.
 */
#[Group('lifebalance')]
final class CountryRangeTableTest extends UnitTestCase {

  /**
   * Known ranges resolve, and other addresses do not.
   */
  public function testKnownRanges(): void {
    $table = new CountryRangeTable(dirname(__DIR__, 3) . '/data');
    $this->assertSame('UA', $table->lookup('1.2.3.0'));
    $this->assertSame('RU', $table->lookup('139.100.160.0'));
    $this->assertNull($table->lookup('8.8.8.8'));
    $this->assertNull($table->lookup('127.0.0.1'));
    $this->assertSame('UA', $table->lookup('2001:470:2006::'));
    $this->assertSame('RU', $table->lookup('2a0c:7b80::'));
    $this->assertSame('UA', $table->lookup('::ffff:1.2.3.0'));
    $this->assertNull($table->lookup('not-an-ip'));
  }

}
