<?php

namespace Drupal\Tests\__NAME__\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Hostile sample: burns every CPU the sandbox gives it, forever.
 *
 * @group __NAME__
 */
class SpinTest extends UnitTestCase {

  /**
   * Spins.
   */
  public function testSpin(): void {
    // One spinner per CPU the sandbox allows (the limit is 2 by default).
    shell_exec('php -r "while (TRUE) {}" >/dev/null 2>&1 &');
    shell_exec('php -r "while (TRUE) {}" >/dev/null 2>&1 &');
    while (TRUE) {
    }
  }

}
