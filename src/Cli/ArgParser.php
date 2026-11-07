<?php

declare(strict_types=1);

namespace Phorward\Cli;

use Phorward\Config\ConfigException;

/**
 * Splits command-line arguments into positional arguments and options.
 *
 * Options are --name value, --name=value, a bare --name (true) or --no-name (false). Which options exist, and
 * whether they take a value, is decided by the caller afterwards; this only reads the shape.
 */
final class ArgParser {

  /**
   * @param string[] $args Arguments after the command name.
   * @param string[] $valueless Option names that never take a value, such as "help" or "dry-run".
   * @return array{positional: string[], options: array<string, string|bool>}
   */
  public static function parse(array $args, array $valueless = []): array {
    $positional = [];
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
      $arg = $args[$i];
      if ($arg === '--') {
        array_push($positional, ...array_slice($args, $i + 1));
        break;
      }
      if (str_starts_with($arg, '--')) {
        $body = substr($arg, 2);
        if ($body === '' || $body[0] === '=') {
          throw new ConfigException("Malformed option \"$arg\".");
        }
        if (str_contains($body, '=')) {
          [$name, $value] = explode('=', $body, 2);
          $options[$name] = $value;
        }
        elseif (str_starts_with($body, 'no-')) {
          $options[substr($body, 3)] = FALSE;
        }
        elseif (in_array($body, $valueless, TRUE)) {
          $options[$body] = TRUE;
        }
        else {
          $next = $args[$i + 1] ?? NULL;
          if ($next !== NULL && !str_starts_with($next, '--')) {
            $options[$body] = $next;
            $i++;
          }
          else {
            $options[$body] = TRUE;
          }
        }
      }
      elseif ($arg === '-h') {
        $options['help'] = TRUE;
      }
      elseif ($arg === '-V') {
        $options['version'] = TRUE;
      }
      elseif (str_starts_with($arg, '-') && $arg !== '-') {
        throw new ConfigException("Unknown option \"$arg\". Options start with two dashes.");
      }
      else {
        $positional[] = $arg;
      }
    }
    return ['positional' => $positional, 'options' => $options];
  }

}
