<?php

declare(strict_types=1);

namespace Phorward\Run;

final class RunResult {

  public function __construct(
    public readonly Outcome $outcome,
    public readonly int $attempts,
    public readonly float $waitedSeconds,
  ) {}

}
