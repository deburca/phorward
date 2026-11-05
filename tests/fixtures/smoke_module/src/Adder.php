<?php

namespace Drupal\smoke_module;

/**
 * Adds numbers.
 */
class Adder {

  /**
   * Returns the sum of two integers.
   */
  public function add(int $a, int $b): int {
    return $a + $b;
  }

}
