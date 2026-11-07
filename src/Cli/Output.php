<?php

declare(strict_types=1);

namespace Phorward\Cli;

/**
 * The two output streams of a command, so tests can capture what it printed.
 */
final class Output {

  /**
   * @param resource $out
   * @param resource $err
   */
  public function __construct(private $out, private $err) {}

  public static function standard(): self {
    return new self(STDOUT, STDERR);
  }

  public function line(string $text = ''): void {
    fwrite($this->out, $text . "\n");
  }

  public function error(string $text): void {
    fwrite($this->err, $text . "\n");
  }

}
