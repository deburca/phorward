<?php

declare(strict_types=1);

namespace Phorward\Report;

use Phorward\Run\Outcome;
use Phorward\Run\RunResult;

/**
 * The report a person reads. Everything that came from the server goes through Sanitize::terminal().
 */
final class HumanReport {

  private const OUTPUT_LINES = 60;

  public function render(RunResult $run, string $host, ?string $project): string {
    $outcome = $run->outcome;
    $lines = [];
    $result = $outcome->result;
    if ($result !== NULL && is_array($result['checks'] ?? NULL)) {
      $lines[] = sprintf('phorward: %s%son %s', $project !== NULL ? "$project " : '', isset($result['image']) ? '(' . $this->s($result['image']) . ') ' : '', $host);
      foreach ($result['checks'] as $check) {
        if (!is_array($check)) {
          continue;
        }
        $status = $this->s($check['status'] ?? '?');
        $lines[] = sprintf('  %-8s %-8s %s', $this->s($check['name'] ?? '?'), strtoupper($status) === 'FAILED' || $status === 'error' ? strtoupper($status) : $status,
          isset($check['duration_ms']) ? sprintf('%.1f s', $check['duration_ms'] / 1000) : $this->s($check['reason'] ?? ''));
      }
      foreach ($result['checks'] as $check) {
        if (is_array($check) && in_array($check['status'] ?? '', ['failed', 'error'], TRUE) && ($check['output'] ?? '') !== '') {
          $lines[] = '';
          $lines[] = sprintf('--- %s output%s', $this->s($check['name'] ?? '?'), !empty($check['output_truncated']) ? ' (the last part; the check printed more)' : '');
          $lines[] = $this->tail($this->s($check['output']), self::OUTPUT_LINES);
        }
      }
    }
    $lines[] = '';
    $lines[] = $this->verdict($outcome);
    if ($outcome->hint !== NULL) {
      $lines[] = $outcome->hint;
    }
    if ($run->attempts > 1) {
      $lines[] = sprintf('(%d attempts, %.1f s spent waiting between them)', $run->attempts, $run->waitedSeconds);
    }
    if ($result !== NULL) {
      $lines[] = $this->serverLine($result);
      $limits = $result['limits']['clamped'] ?? [];
      if (is_array($limits) && $limits !== []) {
        $lines[] = 'limits lowered by the server policy: ' . implode(', ', array_map(
          fn($name) => $this->s($name) . ' ' . $this->s($result['limits']['requested'][$name] ?? '?') . ' to ' . $this->s($result['limits']['applied'][$name] ?? '?'), $limits));
      }
    }
    return implode("\n", $lines) . "\n";
  }

  private function verdict(Outcome $outcome): string {
    return match ($outcome->kind) {
      Outcome::PASSED => 'verdict: passed',
      Outcome::FAILED => 'verdict: failed (the checks above found problems in the code)',
      Outcome::CONFIG => 'verdict: not run: ' . $this->s($outcome->reason),
      Outcome::BUSY => 'verdict: not run: ' . $this->s($outcome->reason) . ' (this says nothing about the code)',
      default => 'verdict: no verdict: ' . $this->s($outcome->reason) . ' (an infrastructure problem; this says nothing about the code)',
    };
  }

  private function serverLine(array $result): string {
    $versions = [];
    foreach ((array) ($result['tool_versions'] ?? []) as $name => $version) {
      if (is_string($version) && preg_match('/^[A-Za-z0-9._-]+$/', (string) $name)) {
        $versions[] = $this->s($name) . ' ' . $this->s($version);
      }
    }
    return sprintf('server: protocol %s, kit %s%s', $this->s($result['protocol'] ?? '?'), $this->s($result['kit_version'] ?? '?'),
      $versions ? '; ' . implode(', ', array_slice($versions, 0, 8)) : '');
  }

  private function tail(string $text, int $lines): string {
    $all = explode("\n", rtrim($text));
    return count($all) <= $lines ? implode("\n", $all) : "[... " . (count($all) - $lines) . " earlier lines left out]\n" . implode("\n", array_slice($all, -$lines));
  }

  private function s(mixed $value): string {
    return Sanitize::terminal(is_scalar($value) ? (string) $value : (string) json_encode($value));
  }

}
