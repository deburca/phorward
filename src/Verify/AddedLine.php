<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * One line a change adds, with the file and line number it lands on.
 */
final class AddedLine {

  public function __construct(
    public readonly string $path,
    public readonly int $number,
    public readonly string $text,
  ) {}

}
