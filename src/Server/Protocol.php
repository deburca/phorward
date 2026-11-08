<?php

declare(strict_types=1);

namespace Phorward\Server;

/**
 * The client's side of docs/protocol.md.
 */
final class Protocol {

  public const VERSION = 1;

  /** The server refuses more than this in --paths. */
  public const MAX_PATHS = 500;

  public static function infoCommand(): string {
    return 'phorward-run info --protocol ' . self::VERSION;
  }

  /**
   * The request for a run. Every value must already have passed its setting's pattern; it is checked again here
   * because it becomes part of a command line on the server.
   *
   * @param string[] $checks Empty for everything the profile offers.
   * @param string[] $tests Paths relative to the project root; empty for all tests.
   * @param array<string, int|float|string|null> $limits memory, cpus, pids and timeout, as asked for.
   * @param string[] $paths Files relative to the project root that lint and phpcs are limited to (and phpcbf fixes); empty for the whole project.
   */
  public static function runCommand(string $profile, string $module, array $checks = [], ?string $php = NULL,
    ?string $db = NULL, array $tests = [], array $limits = [], array $paths = []): string {
    self::require('profile', $profile, '/^[a-z0-9][a-z0-9_-]{0,40}$/');
    self::require('module', $module, '/^[a-z][a-z0-9_]{0,60}$/');
    $parts = ['phorward-run', 'run', '--protocol', (string) self::VERSION, '--profile', $profile];
    if ($php !== NULL) {
      self::require('php', $php, '/^[0-9]{1,2}\.[0-9]{1,2}$/');
      array_push($parts, '--php', $php);
    }
    array_push($parts, '--module', $module);
    if ($checks !== []) {
      foreach ($checks as $check) {
        if (!in_array($check, ['lint', 'phpcbf', 'phpcs', 'phpstan', 'phpunit'], TRUE)) {
          throw new \InvalidArgumentException('Refusing to send an unknown check to the server.');
        }
      }
      array_push($parts, '--checks', implode(',', $checks));
    }
    if ($db !== NULL) {
      self::require('database', $db, '/^(mariadb|sqlite|none)$/');
      array_push($parts, '--db', $db);
    }
    if ($tests !== []) {
      foreach ($tests as $test) {
        self::require('test path', $test, '#^[A-Za-z0-9_./-]+$#');
        if (in_array('..', explode('/', $test), TRUE) || str_starts_with($test, '/') || str_contains($test, '//') || str_starts_with($test, '-')) {
          throw new \InvalidArgumentException('Refusing to send an unsafe test path to the server.');
        }
      }
      array_push($parts, '--tests', implode(',', $tests));
    }
    if ($paths !== []) {
      if (count($paths) > self::MAX_PATHS || count(array_unique($paths)) !== count($paths)) {
        throw new \InvalidArgumentException('Refusing to send more than ' . self::MAX_PATHS . ' paths, or the same path twice.');
      }
      foreach ($paths as $path) {
        self::require('path', $path, '#^[A-Za-z0-9_./-]+$#');
        $segments = explode('/', $path);
        if (array_intersect(['..', '.', ''], $segments) !== [] || str_starts_with($path, '-')) {
          throw new \InvalidArgumentException('Refusing to send an unsafe path to the server.');
        }
      }
      array_push($parts, '--paths', implode(',', $paths));
    }
    $patterns = ['memory' => '/^[0-9]{1,6}[kmg]$/', 'cpus' => '/^[0-9]{1,2}(\.[0-9]{1,2})?$/', 'pids' => '/^[0-9]{1,6}$/', 'timeout' => '/^[0-9]{1,6}$/'];
    foreach ($patterns as $name => $pattern) {
      if (($limits[$name] ?? NULL) !== NULL) {
        $value = is_float($limits[$name]) ? rtrim(rtrim(sprintf('%.2f', $limits[$name]), '0'), '.') : (string) $limits[$name];
        self::require("limit $name", $value, $pattern);
        array_push($parts, "--$name", $value);
      }
    }
    return implode(' ', $parts);
  }

  private static function require(string $name, string $value, string $pattern): void {
    if (!preg_match($pattern, $value)) {
      throw new \InvalidArgumentException("Refusing to send an invalid $name to the server.");
    }
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
