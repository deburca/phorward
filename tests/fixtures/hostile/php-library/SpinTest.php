<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;


/** Hostile sample: burns every CPU the sandbox gives it, forever.
 */
class SpinTest extends TestCase {

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
