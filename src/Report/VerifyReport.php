<?php

declare(strict_types=1);

namespace Phorward\Report;

use Phorward\Verify\GateResult;
use Phorward\Verify\VerifyResult;

/**
 * The report of "phorward verify". It is read by a person, and by a model that must not be confused, so it is
 * short: one line per gate, then the first lines of each failure. Everything that came from the server was
 * sanitised when the gate result was made; it is run through Sanitize again here because it is the last step
 * before a terminal.
 */
final class VerifyReport {

  public function human(VerifyResult $result, string $host, ?string $project): string {
    $lines = [sprintf('phorward verify: %son %s; base %s (%s), %d file(s) changed', $project !== NULL ? "$project " : '', $host,
      Sanitize::terminal($result->base), substr($result->mergeBase, 0, 7), count($result->changed))];
    foreach ($result->gates as $gate) {
      $lines[] = sprintf('  %-9s %-8s %-6s %s%s', $gate->name, $this->status($gate), $gate->where,
        $gate->durationMs !== NULL ? sprintf('%.1f s  ', $gate->durationMs / 1000) : '', Sanitize::terminal($gate->summary));
    }
    foreach ($result->gates as $gate) {
      if ($gate->status === GateResult::FAILED && $gate->detail !== '') {
        $lines[] = '';
        $lines[] = "--- {$gate->name}";
        $lines[] = rtrim(Sanitize::terminal($gate->detail));
      }
    }
    foreach ($result->gates as $gate) {
      if ($gate->status === GateResult::INFRA && $gate->hint !== NULL) {
        $lines[] = '';
        $lines[] = "--- {$gate->name} could not run";
        $lines[] = Sanitize::terminal($gate->hint);
        break;
      }
    }
    if ($result->warnings !== []) {
      $lines[] = '';
      $lines[] = 'warnings (these do not fail the gate):';
      foreach ($result->warnings as $warning) {
        $lines[] = Sanitize::terminal($warning);
      }
    }
    foreach ($result->notes as $note) {
      $lines[] = 'note: ' . Sanitize::terminal($note);
    }
    $lines[] = '';
    $lines[] = $this->verdict($result);
    return implode("\n", $lines) . "\n";
  }

  public function json(VerifyResult $result, string $host): string {
    $first = $result->firstFailure();
    $document = [
      'verdict' => $this->verdictWord($result),
      'exit_code' => $result->exitCode(),
      'host' => $host,
      'base' => $result->base,
      'merge_base' => $result->mergeBase,
      'changed' => $result->changed,
      'php' => $result->php,
      'tests' => $result->tests,
      'gates' => array_map(fn(GateResult $gate) => [
        'name' => $gate->name,
        'status' => $gate->status,
        'where' => $gate->where,
        'summary' => Sanitize::terminal($gate->summary),
        'duration_ms' => $gate->durationMs,
        'excerpt' => Sanitize::terminal($gate->detail),
        'problem' => $gate->problem,
        'hint' => $gate->hint,
      ], $result->gates),
      'first_failure' => $first === NULL ? NULL : ['gate' => $first->name, 'summary' => Sanitize::terminal($first->summary), 'excerpt' => Sanitize::terminal($first->detail)],
      'warnings' => array_map([Sanitize::class, 'terminal'], $result->warnings),
      'notes' => array_map([Sanitize::class, 'terminal'], $result->notes),
    ];
    return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
  }

  private function status(GateResult $gate): string {
    return match ($gate->status) {
      GateResult::FAILED => 'FAILED',
      GateResult::INFRA => 'NOT RUN',
      default => $gate->status,
    };
  }

  private function verdictWord(VerifyResult $result): string {
    return $result->firstFailure() !== NULL ? 'failed' : ($result->exitCode() === 0 ? 'passed' : 'incomplete');
  }

  private function verdict(VerifyResult $result): string {
    $first = $result->firstFailure();
    return match ($this->verdictWord($result)) {
      'passed' => 'verdict: passed (lint, standards and the selected tests are likely to pass in CI; this does not show the logic or the tests are right)',
      'failed' => 'verdict: failed; first failure: ' . $first->name . ' (' . Sanitize::terminal($first->summary) . ')',
      default => 'verdict: incomplete: a gate could not run (an infrastructure problem; this says nothing about the code)',
    };
  }

}
