<?php

declare(strict_types=1);

namespace Phorward\Ssh;

/**
 * A way to run one command on the server. The real one is ssh; tests supply their own.
 */
interface Transport {

  /**
   * @param string $remoteCommand The command line as the server's forced command will see it.
   * @param string|resource|NULL $input Sent to the command's standard input (for a run, the tree as a tar archive).
   * @param float $timeout Seconds to wait for the whole command.
   */
  public function execute(string $remoteCommand, mixed $input = NULL, float $timeout = 60.0): TransportResult;

}
