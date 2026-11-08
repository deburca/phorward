<?php

declare(strict_types=1);

namespace Phorward\Verify;

use Phorward\Config\ConfigException;
use Phorward\Config\ResolvedConfig;
use Phorward\Config\Schema;
use Phorward\Install\TreeArchiver;
use Phorward\Project\ProfileCatalog;
use Phorward\Report\Sanitize;
use Phorward\Run\Outcome;
use Phorward\Run\ResultClassifier;
use Phorward\Run\Runner;
use Phorward\Server\Protocol;
use Phorward\Ssh\Transport;

/**
 * Runs the gates for one change. The cheap ones read the working tree and git and run here; whatever executes the
 * project's code goes to the server, as at most two runs (one per PHP version), through the same retries and
 * exit statuses as "phorward run". A failed gate never stops the later ones: one run gives one full report.
 */
final class Verifier {

  /** Server gates and the protocol check each one runs. */
  private const SERVER_CHECKS = ['syntax' => 'lint', 'phpcs' => 'phpcs', 'phpstan' => 'phpstan', 'phpunit' => 'phpunit'];

  private const EXCERPT_LINES = 40;

  /**
   * @param \Closure(float): void $sleep
   * @param \Closure(string): void $log
   */
  public function __construct(
    private readonly Transport $transport,
    private readonly ProfileCatalog $catalog,
    private readonly TreeArchiver $archiver,
    private readonly \Closure $sleep,
    private readonly \Closure $log,
    private readonly Gitleaks $gitleaks = new Gitleaks(),
  ) {}

  /**
   * @param string[] $only Gate names to run; empty for the configured set.
   * @param string[] $explicitTests Run exactly these tests in the phpunit gate instead of choosing them.
   */
  public function verify(ResolvedConfig $config, array $only, ?string $baseOption, array $explicitTests): VerifyResult {
    $root = $config->projectRoot;
    $profile = $config->get('profile');
    $module = $config->get('module');
    if ($root === NULL || $profile === NULL || $module === NULL) {
      throw new ConfigException('Cannot tell which project this is. Run it inside the project, or set "profile" and '
        . '"module" in phorward.json ("phorward config" shows what was detected).');
    }
    $spec = $this->catalog->verifySpec((string) $profile);
    if ($spec === NULL) {
      throw new ConfigException("This package has no manifest for the profile \"$profile\", so it cannot tell how to verify it.");
    }
    $gates = $this->selectedGates($only, (array) $config->get('gates.skip'));

    $git = new Git($root);
    [$baseRef, $mergeBase] = $git->mergeBase($baseOption ?? $config->get('gates.base'));
    $filter = new PathFilter((array) $config->get('gates.ignore'));
    $changed = $filter->filter($git->changedFiles($mergeBase));
    $php = $this->phpVersions($config, $spec);

    if ($changed === []) {
      $results = [];
      foreach ($gates as $gate) {
        $results[] = $gate === 'changes'
          ? new GateResult('changes', GateResult::FAILED, 'local', "no files changed since $baseRef",
            "No files changed since $baseRef. Nothing was implemented, so there is nothing to pass: make the change, then verify.", 0)
          : new GateResult($gate, GateResult::SKIPPED, $gate === 'tests' || $gate === 'security' ? 'local' : 'server', 'nothing changed');
      }
      return new VerifyResult($results, $baseRef, $mergeBase, [], [], [], [], $php);
    }

    $results = [];
    $notes = [];
    $warnings = [];
    $tests = [];
    $selector = new TestSelector($root, $spec['layout'], $spec['source_extensions']);

    if (in_array('changes', $gates, TRUE)) {
      $results['changes'] = new GateResult('changes', GateResult::PASSED, 'local', count($changed) . ' file(s) changed since ' . $baseRef, '', 0);
    }
    if (in_array('tests', $gates, TRUE)) {
      $results['tests'] = $this->testsGate($selector, $changed, (bool) $config->get('gates.allow_no_tests'));
    }
    $added = [];
    if (array_intersect(['security', 'phpstan'], $gates)) {
      $added = $git->addedLines($mergeBase, $filter);
    }
    if (in_array('security', $gates, TRUE)) {
      [$results['security'], $warnings, $more] = $this->securityGate($root, $mergeBase, $spec, $added);
      array_push($notes, ...$more);
    }

    // Server gates: only those the profile offers.
    $server = [];
    foreach ($gates as $gate) {
      if (!isset(self::SERVER_CHECKS[$gate])) {
        continue;
      }
      if (!in_array(self::SERVER_CHECKS[$gate], $spec['checks'], TRUE)) {
        $results[$gate] = new GateResult($gate, GateResult::SKIPPED, 'server', "the profile \"$profile\" does not offer " . self::SERVER_CHECKS[$gate]);
        continue;
      }
      $server[] = $gate;
    }
    if (in_array('phpunit', $server, TRUE)) {
      if ($explicitTests !== []) {
        $tests = $explicitTests;
      }
      else {
        [$tests, $matched] = $selector->select($changed, (int) $config->get('gates.phpunit.max_files'));
        if ($matched > count($tests)) {
          $notes[] = "capped: $matched tests matched, running the " . count($tests) . ' most relevant';
        }
      }
      if ($tests === []) {
        $results['phpunit'] = new GateResult('phpunit', GateResult::SKIPPED, 'server', 'no test matches the change by name (CI runs them all)');
        $server = array_values(array_diff($server, ['phpunit']));
      }
    }
    foreach ($this->runServerGates($config, $server, $php, $tests) as $name => $result) {
      $results[$name] = $result;
    }

    // A suppressed finding makes phpstan pass without the code being right, so it fails the gate whatever the server said.
    if (isset($results['phpstan'])) {
      $found = (new SuppressionCheck())->check($added);
      if ($found !== []) {
        $text = implode("\n", array_map(fn(Finding $f) => $f->render(), $found));
        $earlier = $results['phpstan'];
        $results['phpstan'] = $earlier->withFailure('a finding was suppressed instead of fixed',
          $text . ($earlier->status === GateResult::FAILED && $earlier->detail !== '' ? "\n\n" . $earlier->detail : ''));
      }
    }

    $ordered = [];
    foreach ($gates as $gate) {
      if (isset($results[$gate])) {
        $ordered[] = $results[$gate];
      }
    }
    return new VerifyResult($ordered, $baseRef, $mergeBase, $changed, $tests, $notes, $warnings, $php);
  }

  /**
   * @param string[] $only
   * @param string[] $skip
   * @return string[]
   */
  private function selectedGates(array $only, array $skip): array {
    foreach ($only as $gate) {
      if (!in_array($gate, Schema::GATES, TRUE)) {
        throw new ConfigException("--only: unknown gate \"$gate\". The gates are " . implode(', ', Schema::GATES) . '.');
      }
    }
    $gates = $only !== [] ? $only : array_values(array_diff(Schema::GATES, $skip));
    // Reported in the fixed order whatever order they were asked in.
    return array_values(array_filter(Schema::GATES, fn(string $gate) => in_array($gate, $gates, TRUE)));
  }

  /**
   * @return array{oldest: string, newest: string}
   */
  private function phpVersions(ResolvedConfig $config, array $spec): array {
    $fallback = (string) ($config->get('php') ?? $this->catalog->defaultPhp((string) $config->get('profile')) ?? '8.3');
    $supported = $spec['php'];
    return [
      'oldest' => (string) ($config->get('gates.php.oldest') ?? ($supported[0] ?? $fallback)),
      'newest' => (string) ($config->get('gates.php.newest') ?? ($supported !== [] ? end($supported) : $fallback)),
    ];
  }

  /**
   * @param string[] $changed
   */
  private function testsGate(TestSelector $selector, array $changed, bool $allowNone): GateResult {
    $started = hrtime(TRUE);
    $source = array_filter($changed, fn(string $path) => $selector->isSource($path));
    $tests = array_filter($changed, fn(string $path) => TestSelector::isTest($path));
    $ms = (int) ((hrtime(TRUE) - $started) / 1000000);
    if ($source === [] || $tests !== []) {
      return new GateResult('tests', GateResult::PASSED, 'local', $source === [] ? 'no source changed' : count($tests) . ' test file(s) changed', '', $ms);
    }
    if ($allowNone) {
      return new GateResult('tests', GateResult::PASSED, 'local', 'source changed without a test (allowed by gates.allow_no_tests)', '', $ms);
    }
    return new GateResult('tests', GateResult::FAILED, 'local', 'source changed but no test was added or changed',
      "Source changed but no test file was added or changed:\n  " . implode("\n  ", array_slice($source, 0, 10))
      . "\nAdd or update a test for the change.", $ms);
  }

  /**
   * @param AddedLine[] $added
   * @return array{0: GateResult, 1: string[], 2: string[]} The gate, the warnings, and notes.
   */
  private function securityGate(string $root, string $mergeBase, array $spec, array $added): array {
    $started = hrtime(TRUE);
    $notes = [];
    $findings = (new SecurityScanner($spec['fail'], $spec['warn'], $spec['scan_extensions']))->scan($added);
    $fails = array_filter($findings, fn(Finding $f) => $f->level === Finding::FAIL);
    $warnings = array_map(fn(Finding $f) => $f->render(), array_filter($findings, fn(Finding $f) => $f->level === Finding::WARN));
    $detail = implode("\n", array_map(fn(Finding $f) => $f->render(), $fails));
    $leaks = $this->gitleaks->scan($root, $mergeBase);
    if ($leaks === NULL) {
      $notes[] = 'gitleaks is not installed: the secret scan was skipped';
    }
    elseif (!$leaks[0]) {
      $fails[] = TRUE;
      $detail .= ($detail !== '' ? "\n\n" : '') . "gitleaks found a possible secret:\n" . Sanitize::terminal($leaks[1]);
    }
    $ms = (int) ((hrtime(TRUE) - $started) / 1000000);
    $gate = $fails === []
      ? new GateResult('security', GateResult::PASSED, 'local', $warnings === [] ? 'nothing found in the added lines' : count($warnings) . ' warning(s)', '', $ms)
      : new GateResult('security', GateResult::FAILED, 'local', count($fails) . ' problem(s) in the added lines', $detail, $ms);
    return [$gate, $warnings, $notes];
  }

  /**
   * Sends the gates that execute project code, one run per PHP version (one in all when the two are the same).
   *
   * @param string[] $gates Server gates to run.
   * @param array{oldest: string, newest: string} $php
   * @param string[] $tests
   * @return array<string, GateResult>
   */
  private function runServerGates(ResolvedConfig $config, array $gates, array $php, array $tests): array {
    if ($gates === []) {
      return [];
    }
    $groups = [];
    foreach ($gates as $gate) {
      $groups[$gate === 'phpunit' ? $php['newest'] : $php['oldest']][] = $gate;
    }
    $host = $config->get('host');
    if ($host === NULL) {
      throw new ConfigException('No server is configured. Set "host" to an SSH alias or user@host in '
        . '~/.config/phorward/config.json, or export PHORWARD_HOST. "phorward doctor" will check the rest.');
    }
    $limits = ['memory' => $config->get('limits.memory'), 'cpus' => $config->get('limits.cpus'),
      'pids' => $config->get('limits.pids'), 'timeout' => $config->get('limits.timeout')];
    $tar = NULL;
    $archive = function () use (&$tar, $config): string {
      return $tar ??= $this->archiver->create($config->projectRoot, (array) $config->get('sync.exclude'), (bool) $config->get('sync.include_vendor'), ['.'],
        (bool) $config->get('sync.respect_gitignore'), TreeArchiver::parseSize((string) $config->get('sync.max_size')));
    };
    $timeout = (float) ($limits['timeout'] ?? 900) + 60 + (float) $config->get('connect_timeout');
    $runner = new Runner($this->transport, new ResultClassifier(), $this->sleep, $this->log);

    $results = [];
    foreach ($groups as $version => $names) {
      $checks = array_map(fn(string $gate) => self::SERVER_CHECKS[$gate], $names);
      try {
        $command = Protocol::runCommand((string) $config->get('profile'), (string) $config->get('module'), $checks, $version,
          $config->get('database'), in_array('phpunit', $names, TRUE) ? $tests : [], $limits);
      }
      catch (\InvalidArgumentException $e) {
        throw new ConfigException($e->getMessage());
      }
      ($this->log)('running ' . implode(', ', $names) . " on PHP $version");
      $run = $runner->run($command, $archive, (int) $config->get('retries'), (float) $config->get('backoff'),
        (int) $config->get('wait'), $timeout);
      foreach ($this->gateResults($names, $run->outcome, "PHP $version") as $name => $result) {
        $results[$name] = $result;
      }
    }
    return $results;
  }

  /**
   * @param string[] $names
   * @return array<string, GateResult>
   */
  private function gateResults(array $names, Outcome $outcome, string $where): array {
    $results = [];
    if (!in_array($outcome->kind, [Outcome::PASSED, Outcome::FAILED], TRUE)) {
      foreach ($names as $name) {
        $results[$name] = new GateResult($name, GateResult::INFRA, 'server', Sanitize::terminal($outcome->reason), '', NULL, $outcome->kind, $outcome->hint);
      }
      return $results;
    }
    $byCheck = [];
    foreach ((array) ($outcome->result['checks'] ?? []) as $check) {
      if (is_array($check) && is_string($check['name'] ?? NULL)) {
        $byCheck[$check['name']] = $check;
      }
    }
    foreach ($names as $name) {
      $check = $byCheck[self::SERVER_CHECKS[$name]] ?? NULL;
      $ms = is_array($check) && isset($check['duration_ms']) ? (int) $check['duration_ms'] : NULL;
      if ($check === NULL) {
        $results[$name] = new GateResult($name, GateResult::INFRA, 'server', 'the server returned no result for this check', '', NULL, Outcome::INFRA);
        continue;
      }
      $status = $check['status'] ?? '';
      $reason = Sanitize::terminal(is_scalar($check['reason'] ?? NULL) ? (string) $check['reason'] : '');
      $results[$name] = match ($status) {
        'passed' => new GateResult($name, GateResult::PASSED, 'server', $where, '', $ms),
        'failed' => new GateResult($name, GateResult::FAILED, 'server', $where, $this->excerpt((string) ($check['output'] ?? '')), $ms),
        'error' => new GateResult($name, GateResult::INFRA, 'server', $reason !== '' ? $reason : 'the check could not run', '', $ms, Outcome::INFRA),
        default => new GateResult($name, GateResult::SKIPPED, 'server', $reason !== '' ? $reason : (string) $status, '', $ms),
      };
    }
    return $results;
  }

  /**
   * The first lines of a log, safe to print: a model reads this, so colour codes and control characters go.
   */
  private function excerpt(string $output): string {
    $lines = explode("\n", rtrim(Sanitize::terminal($output)));
    $head = array_slice($lines, 0, self::EXCERPT_LINES);
    $more = count($lines) - count($head);
    return implode("\n", $head) . ($more > 0 ? "\n[... $more more lines]" : '');
  }

}
