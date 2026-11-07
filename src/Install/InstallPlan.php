<?php

declare(strict_types=1);

namespace Phorward\Install;

/**
 * Everything server:install would do, in order, plus what it prints for the operator afterwards.
 */
final class InstallPlan {

  /**
   * @param Step[] $steps
   * @param string[] $notes Lines printed after the steps.
   */
  public function __construct(
    public readonly Step $preflight,
    public readonly array $steps,
    public readonly array $notes,
  ) {}

}
