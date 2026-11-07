<?php

declare(strict_types=1);

namespace Phorward\Verify;

use Phorward\Cli\Application;
use Phorward\Run\Outcome;

/**
 * Everything one "phorward verify" learned: the gates in order, and the facts around them.
 */
final class VerifyResult {

  /**
   * @param GateResult[] $gates In the order they are reported.
   * @param string[] $changed Paths judged.
   * @param string[] $tests The tests chosen for phpunit.
   * @param string[] $notes Things worth saying that are not a verdict: a cap, a missing scanner.
   * @param string[] $warnings Security warnings, which are printed and do not fail.
   * @param array<string, string> $php PHP version used by each group of gates ("oldest", "newest").
   */
  public function __construct(
    public readonly array $gates,
    public readonly string $base,
    public readonly string $mergeBase,
    public readonly array $changed,
    public readonly array $tests = [],
    public readonly array $notes = [],
    public readonly array $warnings = [],
    public readonly array $php = [],
  ) {}

  public function firstFailure(): ?GateResult {
    foreach ($this->gates as $gate) {
      if ($gate->status === GateResult::FAILED) {
        return $gate;
      }
    }
    return NULL;
  }

  /**
   * A failed gate wins: the model has something to fix either way. Otherwise the machinery's problems, most
   * persistent first: a wrong setup (2), a busy server (75), then anything else that broke (70).
   */
  public function exitCode(): int {
    if ($this->firstFailure() !== NULL) {
      return Application::EXIT_FAILED;
    }
    $problems = array_map(fn(GateResult $gate) => $gate->problem, array_filter($this->gates, fn(GateResult $gate) => $gate->status === GateResult::INFRA));
    foreach ([Outcome::CONFIG => Application::EXIT_USAGE, Outcome::BUSY => Application::EXIT_BUSY, Outcome::INFRA => Application::EXIT_INFRASTRUCTURE] as $kind => $code) {
      if (in_array($kind, $problems, TRUE)) {
        return $code;
      }
    }
    return Application::EXIT_OK;
  }

}
