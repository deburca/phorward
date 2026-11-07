<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;


/** Hostile sample: allocates memory until the sandbox kills it.
 */
class HogTest extends TestCase {

  /**
   * Eats memory.
   */
  public function testHog(): void {
    ini_set('memory_limit', '-1');
    $chunks = [];
    while (TRUE) {
      $chunks[] = str_repeat('x', 10485760);
      // Ramp up over seconds, so a sampler can see the climb.
      usleep(50000);
    }
  }

}
