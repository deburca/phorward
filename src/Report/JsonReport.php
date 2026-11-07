<?php

declare(strict_types=1);

namespace Phorward\Report;

use Phorward\Run\RunResult;

/**
 * The server's result document, unchanged, with a "client" object added that says how the client came to it.
 * When there was no result (ssh failed, say) the document has only "client". Nothing is sanitised: JSON carries
 * any text safely, and a consumer wants what the server said.
 */
final class JsonReport {

  public function render(RunResult $run, string $host): string {
    $outcome = $run->outcome;
    $document = $outcome->result ?? [];
    $document['client'] = [
      'outcome' => $outcome->kind,
      'exit_code' => $outcome->exitCode(),
      'reason' => $outcome->reason,
      'hint' => $outcome->hint,
      'code' => $outcome->code,
      'attempts' => $run->attempts,
      'waited_seconds' => round($run->waitedSeconds, 1),
      'host' => $host,
    ];
    return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
  }

}
