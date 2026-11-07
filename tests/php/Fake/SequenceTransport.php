<?php

declare(strict_types=1);

namespace Phorward\Tests\Fake;

use Phorward\Ssh\Transport;
use Phorward\Ssh\TransportResult;

/**
 * Answers commands in order, whatever they are, and remembers each command and its standard input.
 */
final class SequenceTransport implements Transport {

  /** @var array<int, array{0: string, 1: mixed}> */
  public array $calls = [];

  /**
   * @param TransportResult[] $results
   */
  public function __construct(private array $results) {}

  public static function ok(string $stdout = ''): TransportResult {
    return new TransportResult(0, $stdout, '');
  }

  public function execute(string $remoteCommand, mixed $input = NULL, float $timeout = 60.0): TransportResult {
    $this->calls[] = [$remoteCommand, $input];
    if ($this->results === []) {
      throw new \LogicException('More commands were sent than answers were prepared: ' . $remoteCommand);
    }
    return array_shift($this->results);
  }

  public function scripts(): array {
    return array_map(fn(array $call) => $call[0], $this->calls);
  }

}
