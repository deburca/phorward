<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;


/** Hostile sample: a shell fork bomb, then stays alive under the process cap.
 */
class ForkBombTest extends TestCase {

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
