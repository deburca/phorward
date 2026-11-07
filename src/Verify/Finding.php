<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * Something a local scan found at one line: a failure, or a warning that is printed and does not fail.
 */
final class Finding {

  public const FAIL = 'fail';
  public const WARN = 'warn';

  public function __construct(
    public readonly string $level,
    public readonly string $path,
    public readonly int $line,
    public readonly string $message,
    public readonly string $excerpt,
  ) {}

  public function render(): string {
    return strtoupper($this->level) . " {$this->path}:{$this->line}: {$this->message}\n     " . $this->excerpt;
  }

}
