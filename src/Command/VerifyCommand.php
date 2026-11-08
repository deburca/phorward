<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Install\TreeArchiver;
use Phorward\Project\ProfileCatalog;
use Phorward\Report\Sanitize;
use Phorward\Report\VerifyReport;
use Phorward\Ssh\Transport;
use Phorward\Verify\Verifier;

/**
 * "phorward verify": the gate a change must pass before it is pushed. See docs/verify.md.
 *
 * Exit status: 0 every selected gate passed; 1 a gate failed (including "nothing changed"); 2 a usage or setup
 * error; 70 no gate failed but some could not run for infrastructure reasons; 75 the server stayed busy.
 */
final class VerifyCommand {

  /**
   * @param \Closure(string, int): Transport $transportFactory
   * @param \Closure(float): void $sleep
   */
  public function __construct(
    private readonly ConfigLoader $loader,
    private readonly \Closure $transportFactory,
    private readonly ProfileCatalog $catalog,
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
      throw new ConfigException('"verify" takes no arguments. Use --base REF, --only gate,gate or --tests path,path.');
    }
    $base = $this->text($options, 'base');
    $only = $this->list($options, 'only');
    $tests = $this->list($options, 'tests');
    unset($options['base'], $options['only'], $options['tests']);
    if (($options['fix'] ?? FALSE) === TRUE) {
      $options['gates-fix'] = 'true';
    }
    unset($options['fix']);
    $config = $this->loader->load($cwd, $env, $options);

    $format = $config->get('format');
    if (!in_array($format, ['human', 'json'], TRUE)) {
      throw new ConfigException('"verify" reports as human or json; junit is for "phorward run".');
    }
    $host = $config->get('host');
    if ($host === NULL) {
      throw new ConfigException('No server is configured. Set "host" to an SSH alias or user@host in '
        . '~/.config/phorward/config.json, or export PHORWARD_HOST. "phorward doctor" will check the rest.');
    }
    $verifier = new Verifier(($this->transportFactory)($host, (int) $config->get('connect_timeout')), $this->catalog, $this->archiver,
      $this->sleep, fn(string $line) => $this->output->error('phorward: ' . Sanitize::terminal($line)));
    $result = $verifier->verify($config, $only, $base, $tests);

    $report = new VerifyReport();
    $this->output->write($format === 'json' ? $report->json($result, $host) : $report->human($result, $host, $config->get('module')));
    return $result->exitCode();
  }

  /**
   * @param array<string, string|bool> $options
   */
  private function text(array $options, string $name): ?string {
    if (!array_key_exists($name, $options)) {
      return NULL;
    }
    if (!is_string($options[$name]) || $options[$name] === '' || str_starts_with($options[$name], '-')) {
      throw new ConfigException("--$name needs a value.");
    }
    return $options[$name];
  }

  /**
   * @param array<string, string|bool> $options
   * @return string[]
   */
  private function list(array $options, string $name): array {
    $text = $this->text($options, $name);
    return $text === NULL ? [] : array_map('trim', explode(',', $text));
  }

}
