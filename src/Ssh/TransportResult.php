<?php

declare(strict_types=1);

namespace Phorward\Ssh;

/**
 * What came back from running a command on the server: the exit status (NULL when it timed out or never
 * started), what it printed, and whether the client gave up waiting.
 */
final class TransportResult {

  public function __construct(
    public readonly ?int $exitCode,
    public readonly string $stdout,
    public readonly string $stderr,
    public readonly bool $timedOut = FALSE,
  ) {}

}
