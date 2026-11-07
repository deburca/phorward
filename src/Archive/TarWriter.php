<?php

declare(strict_types=1);

namespace Phorward\Archive;

/**
 * Builds a tar archive in memory. Only what the client needs a small archive for, such as the one-file tree
 * that doctor sends to prove a run works end to end; real trees are streamed with tar itself.
 */
final class TarWriter {

  /**
   * A ustar archive holding one regular file.
   */
  public static function singleFile(string $name, string $content): string {
    if ($name === '' || strlen($name) > 100 || str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, "\0")) {
      throw new \InvalidArgumentException('The file name must be a short relative path.');
    }
    $header = str_pad($name, 100, "\0")
      . sprintf("%07o\0", 0644)
      . sprintf("%07o\0", 0)
      . sprintf("%07o\0", 0)
      . sprintf("%011o\0", strlen($content))
      . sprintf("%011o\0", 0)
      . '        '
      . '0'
      . str_repeat("\0", 100)
      . "ustar\0" . '00'
      . str_pad('', 32, "\0") . str_pad('', 32, "\0")
      . sprintf("%07o\0", 0) . sprintf("%07o\0", 0)
      . str_repeat("\0", 155);
    $header = str_pad($header, 512, "\0");
    $sum = array_sum(array_map('ord', str_split($header)));
    $header = substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);
    $padding = (512 - strlen($content) % 512) % 512;
    return $header . $content . str_repeat("\0", $padding) . str_repeat("\0", 1024);
  }

}
