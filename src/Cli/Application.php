<?php

declare(strict_types=1);

namespace Phorward\Cli;

use Phorward\Command\ConfigCommand;
use Phorward\Command\DoctorCommand;
use Phorward\Command\HarnessInstallCommand;
use Phorward\Command\RunCommand;
use Phorward\Command\ServerInstallCommand;
use Phorward\Command\VerifyCommand;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Config\Schema;
use Phorward\Harness\TemplateInstaller;
use Phorward\Install\InstallPlanner;
use Phorward\Install\TreeArchiver;
use Phorward\Project\ProfileCatalog;
use Phorward\Ssh\SshTransport;

/**
 * Finds the command in the arguments and runs it. Exit codes match phorward-run's: 0 passed, 1 a check
 * failed, 2 a configuration or usage error, 70 an infrastructure error, 75 the server stayed busy.
 */
final class Application {

  public const VERSION = '0.2.0';

  public const EXIT_OK = 0;
  public const EXIT_FAILED = 1;
  public const EXIT_USAGE = 2;
  public const EXIT_INFRASTRUCTURE = 70;
  public const EXIT_BUSY = 75;

  private const COMMANDS = [
    'config' => 'Print the resolved configuration and where each value came from',
    'run' => 'Send this project to the server and run its checks there',
    'verify' => 'Run the gates a change must pass before it is pushed: lint, standards, static analysis, tests, security',
    'doctor' => 'Check the connection, the server and the image, and say how to fix what is wrong',
    'harness:install' => 'Install the opencode agents, commands and retry loop that use "verify" as their gate',
    'server:install' => 'Install or update the server side (prints a plan; --apply runs it over ssh)',
  ];

  /** @var \Closure(string, int): \Phorward\Ssh\Transport */
  private readonly \Closure $transportFactory;

  /** @var \Closure(float): void */
  private readonly \Closure $sleep;

  /**
   * @param \Closure(string, int): \Phorward\Ssh\Transport|NULL $transportFactory Makes the transport for a host and
   *   connect timeout; ssh unless a test supplies another.
   */
  public function __construct(
    private readonly Schema $schema,
    private readonly Output $output,
    ?\Closure $transportFactory = NULL,
    private readonly ?ProfileCatalog $catalog = NULL,
    ?\Closure $sleep = NULL,
  ) {
    $this->sleep = $sleep ?? static function (float $seconds): void {
      usleep((int) round($seconds * 1000000));
    };
    $this->transportFactory = $transportFactory ?? static fn(string $host, int $timeout) => new SshTransport($host, $timeout);
  }

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
      if ($command === 'run') {
        $parsed = ArgParser::parse($rest, ['help']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $run = new RunCommand(new ConfigLoader($this->schema), $this->transportFactory, new TreeArchiver(), $this->output, $this->sleep);
        return $run->run($parsed['positional'], $parsed['options'], $env, $cwd);
      }
      if ($command === 'verify') {
        $parsed = ArgParser::parse($rest, ['help', 'fix']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $verify = new VerifyCommand(new ConfigLoader($this->schema), $this->transportFactory, $this->catalog ?? ProfileCatalog::bundled(),
          new TreeArchiver(), $this->output, $this->sleep);
        return $verify->run($parsed['positional'], $parsed['options'], $env, $cwd);
      }
      if ($command === 'doctor') {
        $parsed = ArgParser::parse($rest, ['help']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $doctor = new DoctorCommand(new ConfigLoader($this->schema), $this->transportFactory,
          $this->catalog ?? ProfileCatalog::bundled(), $this->output);
        return $doctor->run($parsed['positional'], $parsed['options'], $env, $cwd);
      }
      if ($command === 'harness:install') {
        $parsed = ArgParser::parse($rest, ['help', 'force', 'dry-run']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $install = new HarnessInstallCommand(new ConfigLoader($this->schema),
          new TemplateInstaller($this->catalog ?? ProfileCatalog::bundled(), dirname(__DIR__, 2) . '/templates'), $this->output);
        return $install->run($parsed['positional'], $parsed['options'], $env, $cwd);
      }
      if ($command === 'server:install') {
        $parsed = ArgParser::parse($rest, ['help', 'apply', 'skip-build', 'skip-smoke']);
        if (isset($parsed['options']['help'])) {
          $this->help();
          return self::EXIT_OK;
        }
        $catalog = $this->catalog ?? ProfileCatalog::bundled();
        $install = new ServerInstallCommand(new ConfigLoader($this->schema), $this->transportFactory,
          new InstallPlanner($catalog, new TreeArchiver(), dirname(__DIR__, 2)), $this->output);
        return $install->run($parsed['positional'], $parsed['options'], $env, $cwd);
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
      $this->output->line(sprintf('  %-15s %s', $name, $description));
    }
    $this->output->line();
    $this->output->line('Settings can be given as flags (for example --host), as PHORWARD_* environment variables,');
    $this->output->line('in phorward.json, or in ~/.config/phorward/config.json. See docs/configuration.md.');
  }

}
