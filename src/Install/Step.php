<?php

declare(strict_types=1);

namespace Phorward\Install;

/**
 * One thing the installer does on the server: a shell script run as `sh -c` over ssh, optionally fed a payload
 * (a tar archive, a policy file) on its standard input.
 */
final class Step {

  /**
   * @param string $script The script, exactly as it would be run.
   * @param \Closure(): string|NULL $input Produces the payload for standard input; NULL for none.
   * @param string|NULL $payload A one-line description of that payload, for the plan.
   */
  public function __construct(
    public readonly string $title,
    public readonly string $script,
    public readonly ?\Closure $input = NULL,
    public readonly ?string $payload = NULL,
    public readonly float $timeout = 120.0,
  ) {}

  /**
   * The command line handed to ssh.
   */
  public function remoteCommand(): string {
    return 'sh -c ' . Quote::sh($this->script);
  }

}
