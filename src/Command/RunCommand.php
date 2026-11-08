<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Install\TreeArchiver;
use Phorward\Report\HumanReport;
use Phorward\Report\JsonReport;
use Phorward\Report\JunitReport;
use Phorward\Report\Sanitize;
use Phorward\Run\ResultClassifier;
use Phorward\Run\Runner;
use Phorward\Server\Protocol;
use Phorward\Ssh\Transport;

/**
 * "phorward run": sends the working tree to the server, runs the checks there in a throwaway sandbox, and reports.
 *
 * The exit status says what happened to the checks, and keeps "the code is wrong" apart from "the machinery is
 * wrong": 0 passed, 1 a check failed, 2 the request or the setup is wrong (nothing a retry fixes), 70 an
 * infrastructure error, 75 the server stayed busy. A caller that treats 70 and 75 as "try later" and 1 as "fix
 * the code" never blames a model for a server that fell over.
 */
final class RunCommand {

  /** The server refuses more than this anyway (its default policy); failing here saves the upload. */
  private const MAX_ARCHIVE_BYTES = 536870912;

  /**
   * @param \Closure(string, int): Transport $transportFactory
   * @param \Closure(float): void $sleep
   */
  public function __construct(
    private readonly ConfigLoader $loader,
    private readonly \Closure $transportFactory,
    private readonly TreeArchiver $archiver,
    private readonly Output $output,
    private readonly \Closure $sleep,
  ) {}

  /**
   * @param string[] $positional
   * @param array<string, string|bool> $options
   * @param array<string, string> $env
   */
  public function run(array $positional, array $options, array $env, string $cwd): int {
    if ($positional !== []) {
      throw new ConfigException('"run" takes no arguments. Settings are options, for example --checks lint,phpcs; '
        . 'to run particular tests use --tests path,path.');
    }
    $tests = [];
    if (array_key_exists('tests', $options)) {
      if (!is_string($options['tests']) || $options['tests'] === '') {
        throw new ConfigException('--tests needs a comma-separated list of paths relative to the project root.');
      }
      $tests = array_map('trim', explode(',', $options['tests']));
      unset($options['tests']);
    }
    $paths = [];
    if (array_key_exists('paths', $options)) {
      if (!is_string($options['paths']) || $options['paths'] === '') {
        throw new ConfigException('--paths needs a comma-separated list of files relative to the project root.');
      }
      $paths = array_map('trim', explode(',', $options['paths']));
      unset($options['paths']);
    }
    $config = $this->loader->load($cwd, $env, $options);

    $host = $config->get('host');
    if ($host === NULL) {
      throw new ConfigException('No server is configured. Set "host" to an SSH alias or user@host in '
        . '~/.config/phorward/config.json, or export PHORWARD_HOST. "phorward doctor" will check the rest.');
    }
    if ($config->projectRoot === NULL || $config->get('profile') === NULL || $config->get('module') === NULL) {
      throw new ConfigException('Cannot tell which project this is. Run it inside the project, or set "profile" and '
        . '"module" in phorward.json ("phorward config" shows what was detected).');
    }
    $limits = ['memory' => $config->get('limits.memory'), 'cpus' => $config->get('limits.cpus'),
      'pids' => $config->get('limits.pids'), 'timeout' => $config->get('limits.timeout')];
    try {
      $command = Protocol::runCommand((string) $config->get('profile'), (string) $config->get('module'),
        (array) ($config->get('checks') ?? []), $config->get('php'), $config->get('database'), $tests, $limits, $paths);
    }
    catch (\InvalidArgumentException $e) {
      throw new ConfigException($e->getMessage());
    }

    $root = $config->projectRoot;
    $archive = function () use ($root, $config): string {
      $tar = $this->archiver->create($root, (array) $config->get('sync.exclude'), (bool) $config->get('sync.include_vendor'), ['.'],
        (bool) $config->get('sync.respect_gitignore'), TreeArchiver::parseSize((string) $config->get('sync.max_size')));
      if (strlen($tar) > self::MAX_ARCHIVE_BYTES) {
        throw new ConfigException(sprintf('The tree to send is %d MiB, over the %d MiB the server accepts. Leave things out '
          . 'with sync.exclude.', strlen($tar) / 1048576, self::MAX_ARCHIVE_BYTES / 1048576));
      }
      return $tar;
    };

    $timeout = (float) ($limits['timeout'] ?? 900) + 60 + (float) $config->get('connect_timeout');
    $runner = new Runner(($this->transportFactory)($host, (int) $config->get('connect_timeout')), new ResultClassifier(),
      $this->sleep, fn(string $line) => $this->output->error('phorward: ' . Sanitize::terminal($line)));
    $run = $runner->run($command, $archive, (int) $config->get('retries'), (float) $config->get('backoff'),
      (int) $config->get('wait'), $timeout);

    $report = match ($config->get('format')) {
      'json' => (new JsonReport())->render($run, $host),
      'junit' => (new JunitReport())->render($run),
      default => (new HumanReport())->render($run, $host, $config->get('module')),
    };
    $this->output->write($report);
    return $run->outcome->exitCode();
  }

}
