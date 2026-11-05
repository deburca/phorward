<?php

namespace Drupal\Tests\smoke_module\Unit;

use Drupal\smoke_module\Adder;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the adder.
 *
 * @group smoke_module
 */
class AdderTest extends UnitTestCase {

  /**
   * Adds two numbers.
   */
  public function testAdd(): void {
    $this->assertSame(5, (new Adder())->add(2, 3));
  }

}
