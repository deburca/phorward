<?php

declare(strict_types=1);

namespace Phorward\Server;

/**
 * The client's side of docs/protocol.md.
 */
final class Protocol {

  public const VERSION = 1;

  public static function infoCommand(): string {
    return 'phorward-run info --protocol ' . self::VERSION;
  }

  /**
   * The request for a run. Every value must already have passed its setting's pattern; it is checked again here
   * because it becomes part of a command line on the server.
   *
   * @param string[] $checks
   */
  public static function runCommand(string $profile, string $php, string $module, array $checks): string {
    foreach ([
      'profile' => [$profile, '/^[a-z0-9][a-z0-9_-]{0,40}$/'],
      'php' => [$php, '/^[0-9]{1,2}\.[0-9]{1,2}$/'],
      'module' => [$module, '/^[a-z][a-z0-9_]{0,60}$/'],
    ] as $name => [$value, $pattern]) {
      if (!preg_match($pattern, $value)) {
        throw new \InvalidArgumentException("Refusing to send an invalid $name to the server.");
      }
    }
    foreach ($checks as $check) {
      if (!in_array($check, ['lint', 'phpcs', 'phpstan', 'phpunit'], TRUE)) {
        throw new \InvalidArgumentException('Refusing to send an unknown check to the server.');
      }
    }
    return sprintf('phorward-run run --protocol %d --profile %s --php %s --module %s --checks %s',
      self::VERSION, $profile, $php, $module, implode(',', $checks));
  }

  /**
   * The JSON object a command printed, or NULL if it printed anything else.
   *
   * @return array<string, mixed>|null
   */
  public static function decode(string $stdout): ?array {
    $data = json_decode($stdout, TRUE);
    return is_array($data) && !array_is_list($data) ? $data : NULL;
  }

}
