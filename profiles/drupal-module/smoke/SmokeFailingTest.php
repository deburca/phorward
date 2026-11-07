<?php

namespace Drupal\Tests\__NAME__\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Deliberately failing test.
 *
 * @group __NAME__
 */
class SmokeFailingTest extends UnitTestCase {

  /**
   * Fails.
   */
  public function testFails(): void {
    $this->assertSame(1, 2);
  }

}
