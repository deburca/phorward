<?php

declare(strict_types=1);

namespace Phorward\Ssh;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command on the server with ssh. Everything about the connection (port, user, identity file, ProxyJump)
 * is left to the user's ssh configuration; this passes only a host, a connection timeout and the command.
 */
final class SshTransport implements Transport {

  public function __construct(
    private readonly string $host,
    private readonly int $connectTimeout = 10,
    private readonly string $ssh = 'ssh',
  ) {
    // Defence in depth: the configuration already refuses this, but a host must never be able to become an option.
    if (!preg_match('/^[A-Za-z0-9._@][A-Za-z0-9._@-]{0,252}$/', $host)) {
      throw new \InvalidArgumentException('The host is not a valid SSH alias or user@host.');
    }
  }

  public function execute(string $remoteCommand, mixed $input = NULL, float $timeout = 60.0): TransportResult {
    $process = new Process([
      $this->ssh,
      '-o', 'BatchMode=yes',
      '-o', 'ConnectTimeout=' . $this->connectTimeout,
      '--',
      $this->host,
      $remoteCommand,
    ]);
    $process->setTimeout($timeout);
    if ($input !== NULL) {
      $process->setInput($input);
    }
    try {
      $process->run();
    }
    catch (ProcessTimedOutException) {
      return new TransportResult(NULL, $process->getOutput(), $process->getErrorOutput(), TRUE);
    }
    return new TransportResult($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
  }

}
