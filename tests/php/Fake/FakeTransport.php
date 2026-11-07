<?php

declare(strict_types=1);

namespace Phorward\Tests\Fake;

use Phorward\Ssh\Transport;
use Phorward\Ssh\TransportResult;

/**
 * Answers commands from a list instead of calling ssh, and remembers what it was asked.
 */
final class FakeTransport implements Transport {

  /** @var array<int, array{0: string, 1: mixed}> */
  public array $calls = [];

  /**
   * @param array<string, TransportResult> $responses Keyed by the start of the command they answer.
   */
  public function __construct(private array $responses) {}

  public function execute(string $remoteCommand, mixed $input = NULL, float $timeout = 60.0): TransportResult {
    $this->calls[] = [$remoteCommand, $input];
    foreach ($this->responses as $prefix => $result) {
      if (str_starts_with($remoteCommand, $prefix)) {
        return $result;
      }
    }
    throw new \LogicException("No response set for: $remoteCommand");
  }

  public function commands(): array {
    return array_map(fn($call) => $call[0], $this->calls);
  }

}
