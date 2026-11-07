<?php

namespace Drupal\Tests\__NAME__\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Hostile sample: allocates memory until the sandbox kills it.
 *
 * @group __NAME__
 */
class HogTest extends UnitTestCase {

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
