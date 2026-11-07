<?php

declare(strict_types=1);

namespace Phorward\Cli;

use Phorward\Command\ConfigCommand;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Config\Schema;

/**
 * Finds the command in the arguments and runs it. Exit codes match phorward-run's: 0 passed, 1 a check
 * failed, 2 a configuration or usage error, 70 an infrastructure error, 75 the server stayed busy.
 */
final class Application {

  public const VERSION = '0.0.0-dev';

  public const EXIT_OK = 0;
  public const EXIT_FAILED = 1;
  public const EXIT_USAGE = 2;
  public const EXIT_INFRASTRUCTURE = 70;
  public const EXIT_BUSY = 75;

  private const COMMANDS = ['config' => 'Print the resolved configuration and where each value came from'];

  public function __construct(private readonly Schema $schema, private readonly Output $output) {}

  /**
   * @param string[] $argv The arguments, without the program name.
   * @param array<string, string> $env
   */
  public function run(array $argv, array $env, string $cwd): int {
    try {
      $command = $argv[0] ?? 'help';
      $rest = array_slice($argv, 1);
      if (in_array($command, ['--version', '-V', 'version'], TRUE)) {
        $this->output->line('phorward ' . self::VERSION);
        return self::EXIT_OK;
      }
      if (in_array($command, ['help', '--help', '-h'], TRUE)) {
        $this->help();
        return self::EXIT_OK;
      }
      if ($command === 'config') {
        $parsed = ArgParser::parse($rest, ['help']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $command = new ConfigCommand($this->schema, new ConfigLoader($this->schema), $this->output);
        return $command->run($parsed['positional'], $parsed['options'], $env, $cwd);
      }
      $this->output->error("Unknown command \"$command\". Run \"phorward help\".");
      return self::EXIT_USAGE;
    }
    catch (ConfigException $e) {
      $this->output->error('phorward: ' . $e->getMessage());
      return self::EXIT_USAGE;
    }
  }

  private function help(): void {
    $this->output->line('phorward ' . self::VERSION);
    $this->output->line();
    $this->output->line('Usage: phorward <command> [options]');
    $this->output->line();
    $this->output->line('Commands:');
    foreach (self::COMMANDS as $name => $description) {
      $this->output->line(sprintf('  %-10s %s', $name, $description));
    }
    $this->output->line();
    $this->output->line('Settings can be given as flags (for example --host), as PHORWARD_* environment variables,');
    $this->output->line('in phorward.json, or in ~/.config/phorward/config.json. See docs/configuration.md.');
  }

}
