<?php

namespace Drupal\Tests\__NAME__\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Hostile sample: a shell fork bomb, then stays alive under the process cap.
 *
 * @group __NAME__
 */
class ForkBombTest extends UnitTestCase {

  /**
   * Forks until the pids limit stops it.
   */
  public function testForkBomb(): void {
    shell_exec('sh -c "f() { f | f & }; f" >/dev/null 2>&1 &');
    while (TRUE) {
      sleep(1);
    }
  }

}
