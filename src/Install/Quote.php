<?php

declare(strict_types=1);

namespace Phorward\Install;

/**
 * Quoting for the POSIX shell on the server. Every value that goes into a remote command is checked against a
 * strict pattern first; quoting it as well means a mistake in one place is not enough to run something else.
 */
final class Quote {

  public static function sh(string $value): string {
    if ($value !== '' && preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value)) {
      return $value;
    }
    return "'" . str_replace("'", "'\\''", $value) . "'";
  }

}
