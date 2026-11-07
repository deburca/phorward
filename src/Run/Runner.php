<?php

declare(strict_types=1);

namespace Phorward\Run;

use Phorward\Ssh\Transport;

/**
 * Sends one run to the server and retries what is worth retrying, within bounds: transient infrastructure
 * failures up to `retries` times with a doubling wait, and a busy server for at most `wait` seconds in all.
 * Failed checks, configuration errors and resource limits are returned at once.
 */
final class Runner {

  private const MAX_DELAY = 30.0;

  /**
   * @param \Closure(float): void $sleep Waits for the given seconds; a test passes one that only records.
   * @param \Closure(string): void $log Progress for the person watching (not part of the result).
   */
  public function __construct(
    private readonly Transport $transport,
    private readonly ResultClassifier $classifier,
    private readonly \Closure $sleep,
    private readonly \Closure $log,
  ) {}

  /**
   * @param \Closure(): string $archive The tree to send, as a tar archive; asked for once.
   */
  public function run(string $command, \Closure $archive, int $retries, float $backoff, int $wait, float $timeout): RunResult {
    $tar = $archive();
    $attempts = 0;
    $infraFailures = 0;
    $busyWaits = 0;
    $waited = 0.0;
    while (TRUE) {
      $attempts++;
      $outcome = $this->classifier->classify($this->transport->execute($command, $tar, $timeout));
      if (!$outcome->retryable) {
        return new RunResult($outcome, $attempts, $waited);
      }
      if ($outcome->kind === Outcome::BUSY) {
        // At least a second, or a backoff of 0 would never use up the waiting budget.
        $delay = max(1.0, $this->delay($backoff, $busyWaits));
        if ($waited + $delay > $wait) {
          return new RunResult($outcome, $attempts, $waited);
        }
        $busyWaits++;
        ($this->log)(sprintf('The server is busy; trying again in %s s.', $this->shown($delay)));
      }
      else {
        if ($infraFailures >= $retries) {
          return new RunResult($outcome, $attempts, $waited);
        }
        $delay = $this->delay($backoff, $infraFailures);
        $infraFailures++;
        ($this->log)(sprintf('Attempt %d failed (%s); trying again in %s s.', $attempts, $outcome->reason, $this->shown($delay)));
      }
      ($this->sleep)($delay);
      $waited += $delay;
    }
  }

  private function delay(float $backoff, int $n): float {
    return min(self::MAX_DELAY, $backoff * (2 ** $n));
  }

  private function shown(float $seconds): string {
    return rtrim(rtrim(number_format($seconds, 1), '0'), '.');
  }

}
