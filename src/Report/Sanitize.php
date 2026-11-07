<?php

declare(strict_types=1);

namespace Phorward\Report;

/**
 * Text that came from the server is the output of code a model wrote, so it must not be able to drive the user's
 * terminal (escape sequences can move the cursor, rewrite earlier lines or set the window title) or break the XML
 * of a report. These keep the readable part and drop the rest.
 */
final class Sanitize {

  /**
   * For a terminal: keeps printable characters, newlines and tabs; drops escape sequences and other control characters.
   */
  public static function terminal(string $text): string {
    $text = preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $text) ?? '';          // CSI sequences
    $text = preg_replace('/\x1b\][^\x07\x1b]*(\x07|\x1b\\\\)?/', '', $text) ?? ''; // OSC sequences (titles, links)
    $text = preg_replace('/\x1b[0-~]/', '', $text) ?? '';                          // other two-byte escapes, such as ESC c (reset)
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text) ?? '';
    return self::validUtf8($text);
  }

  /**
   * For XML 1.0: drops the characters it cannot carry at all.
   */
  public static function xml(string $text): string {
    $text = self::validUtf8($text);
    return preg_replace('/[^\x09\x0a\x0d\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
  }

  private static function validUtf8(string $text): string {
    return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
  }

}
