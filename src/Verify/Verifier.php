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
use Phorward\Run\RunResult;
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
    [$sourcePaths, $scopePaths, $scopeNote] = $this->scopePaths($changed, $spec, (string) $config->get('gates.scope'));
    if ($scopeNote !== NULL) {
      $notes[] = $scopeNote;
    }
    $selector = new TestSelector($root, $spec['layout'], $spec['source_extensions']);

    if (in_array('changes', $gates, TRUE)) {
      $results['changes'] = new GateResult('changes', GateResult::PASSED, 'local', count($changed) . ' file(s) changed since ' . $baseRef, '', 0);
    }
    if (in_array('tests', $gates, TRUE)) {
      $results['tests'] = $this->testsGate($selector, $changed, (bool) $config->get('gates.allow_no_tests'));
    }
    if ((bool) $config->get('gates.fix') && $sourcePaths !== NULL && $sourcePaths !== [] && in_array('phpcs', $gates, TRUE)
      && in_array('phpcbf', $spec['checks'], TRUE)) {
      array_push($notes, ...$this->autoFix($config, $sourcePaths, $php['oldest']));
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
      if ($scopePaths === [] && in_array($gate, ['syntax', 'phpcs'], TRUE)) {
        $results[$gate] = new GateResult($gate, GateResult::SKIPPED, 'server', 'no PHP source file changed');
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
    $more = [];
    foreach ($this->runServerGates($config, $server, $php, $tests, $scopePaths, $more) as $name => $result) {
      $results[$name] = $result;
    }
    array_push($notes, ...$more);

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
   * @param string[]|null $paths Files the syntax and phpcs gates are limited to; NULL for the whole project.
   * @param string[] $notes Added to when the run needed a fallback.
   * @return array<string, GateResult>
   */
  private function runServerGates(ResolvedConfig $config, array $gates, array $php, array $tests, ?array $paths, array &$notes): array {
    if ($gates === []) {
      return [];
    }
    $groups = [];
    foreach ($gates as $gate) {
      $groups[$gate === 'phpunit' ? $php['newest'] : $php['oldest']][] = $gate;
    }
    [$limits, $archive, $runner, $timeout] = $this->serverContext($config);

    $results = [];
    foreach ($groups as $version => $names) {
      $checks = array_map(fn(string $gate) => self::SERVER_CHECKS[$gate], $names);
      $scoped = $paths !== NULL && array_intersect(['syntax', 'phpcs'], $names) !== [] ? $paths : [];
      $run = $this->send($config, $runner, $archive, $checks, $version, in_array('phpunit', $names, TRUE) ? $tests : [], $limits, $scoped, $timeout);
      if ($scoped !== [] && $run->outcome->kind === Outcome::CONFIG && preg_match('/--paths/', $run->outcome->reason)) {
        $notes[] = 'the server predates --paths, so syntax and phpcs covered the whole project';
        $scoped = [];
        $run = $this->send($config, $runner, $archive, $checks, $version, in_array('phpunit', $names, TRUE) ? $tests : [], $limits, [], $timeout);
      }
      foreach ($this->gateResults($names, $run->outcome, "PHP $version", count($scoped)) as $name => $result) {
        $results[$name] = $result;
      }
    }
    return $results;
  }

  /**
   * Asks the server for phpcbf on the changed files and applies the patch it returns to the working tree.
   *
   * @param string[] $paths
   * @return string[] Notes for the report; a problem here never fails a gate.
   */
  private function autoFix(ResolvedConfig $config, array $paths, string $php): array {
    [$limits, $archive, $runner, $timeout] = $this->serverContext($config);
    $run = $this->send($config, $runner, $archive, ['phpcbf'], $php, [], $limits, $paths, $timeout);
    $outcome = $run->outcome;
    if (!in_array($outcome->kind, [Outcome::PASSED, Outcome::FAILED], TRUE)) {
      $why = $outcome->kind === Outcome::CONFIG && preg_match('/--paths|phpcbf/', $outcome->reason)
        ? 'the server predates phpcbf' : Sanitize::terminal($outcome->reason);
      return ["auto-fix skipped: $why"];
    }
    $entry = NULL;
    foreach ((array) ($outcome->result['checks'] ?? []) as $check) {
      if (is_array($check) && ($check['name'] ?? NULL) === 'phpcbf') {
        $entry = $check;
      }
    }
    if ($entry === NULL || ($entry['status'] ?? '') !== 'passed') {
      return ['auto-fix skipped: phpcbf did not run'];
    }
    if (isset($entry['patch_dropped'])) {
      return ['auto-fix skipped: the patch was ' . ($entry['patch_dropped'] === 'too_large' ? 'too large' : 'not text')];
    }
    $patch = is_string($entry['patch'] ?? NULL) ? $entry['patch'] : '';
    if (trim($patch) === '') {
      return ['phpcbf: nothing to fix in the changed files'];
    }
    try {
      $fixed = (new PatchApplier())->apply((string) $config->projectRoot, $patch, $paths);
    }
    catch (PatchRejected $e) {
      return ['auto-fix skipped: the patch was refused (' . Sanitize::terminal($e->getMessage()) . ')'];
    }
    $list = implode(', ', array_slice($fixed, 0, 8)) . (count($fixed) > 8 ? ', and ' . (count($fixed) - 8) . ' more' : '');
    return ['phpcbf fixed ' . count($fixed) . " file(s) in your working tree: $list"];
  }

  /**
   * @param string[] $checks
   * @param string[] $tests
   * @param array<string, mixed> $limits
   * @param string[] $paths
   */
  private function send(ResolvedConfig $config, Runner $runner, \Closure $archive, array $checks, string $version, array $tests,
    array $limits, array $paths, float $timeout): RunResult {
    try {
      $command = Protocol::runCommand((string) $config->get('profile'), (string) $config->get('module'), $checks, $version,
        $config->get('database'), $tests, $limits, $paths);
    }
    catch (\InvalidArgumentException $e) {
      throw new ConfigException($e->getMessage());
    }
    ($this->log)('running ' . implode(', ', $checks) . " on PHP $version");
    return $runner->run($command, $archive, (int) $config->get('retries'), (float) $config->get('backoff'),
      (int) $config->get('wait'), $timeout);
  }

  /**
   * @return array{0: array<string, mixed>, 1: \Closure, 2: Runner, 3: float} The limits to ask for, the tree archive
   *   (built once, when first needed), the runner, and the wall-clock allowance for one run.
   */
  private function serverContext(ResolvedConfig $config): array {
    if ($config->get('host') === NULL) {
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
    return [$limits, $archive, new Runner($this->transport, new ResultClassifier(), $this->sleep, $this->log), $timeout];
  }

  /**
   * The changed PHP source files (by the profile's extensions), and what the syntax and phpcs gates are limited to.
   * The source files are NULL when they cannot be named to the server (an odd file name, or too many). The scope is
   * NULL for the whole project, with a note when that was not the setting, and an empty list when no source file
   * changed, so there is nothing for those gates to look at. Only a changed phpcs ruleset widens the scope: it can
   * change what every file is judged by, whereas a changed phpstan configuration only concerns phpstan, which always
   * covers the whole project.
   *
   * @param string[] $changed
   * @return array{0: string[]|null, 1: string[]|null, 2: string|null} The source files, the scope, and a note.
   */
  private function scopePaths(array $changed, array $spec, string $scope): array {
    $paths = array_values(array_filter($changed,
      fn(string $path) => in_array(pathinfo($path, PATHINFO_EXTENSION), $spec['source_extensions'], TRUE)));
    $reason = NULL;
    foreach ($paths as $path) {
      if (!preg_match('#^[A-Za-z0-9_./-]+$#', $path) || str_starts_with($path, '-')) {
        $reason = "a changed file has a name that cannot be sent as a path ($path), so syntax and phpcs cover the whole project";
      }
    }
    if ($reason === NULL && (count($paths) > Protocol::MAX_PATHS || strlen(implode(',', $paths)) > 12000)) {
      $reason = count($paths) . ' source files changed, too many to name, so syntax and phpcs cover the whole project';
    }
    if ($reason !== NULL) {
      return [NULL, NULL, $scope === 'changed' ? $reason : NULL];
    }
    if ($scope !== 'changed') {
      return [$paths, NULL, NULL];
    }
    foreach ($changed as $path) {
      if (preg_match('#(^|/)\.?phpcs\.xml(\.dist)?$#', $path)) {
        return [$paths, NULL, "$path changed, so syntax and phpcs cover the whole project"];
      }
    }
    return [$paths, $paths, NULL];
  }

  /**
   * @param string[] $names
   * @return array<string, GateResult>
   */
  private function gateResults(array $names, Outcome $outcome, string $where, int $scoped = 0): array {
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
      $detailWhere = ($check['scope'] ?? NULL) === 'paths' && $scoped > 0 ? "$where, $scoped changed file(s)" : $where;
      $reason = Sanitize::terminal(is_scalar($check['reason'] ?? NULL) ? (string) $check['reason'] : '');
      $results[$name] = match ($status) {
        'passed' => new GateResult($name, GateResult::PASSED, 'server', $detailWhere, '', $ms),
        'failed' => new GateResult($name, GateResult::FAILED, 'server', $detailWhere, $this->excerpt((string) ($check['output'] ?? '')), $ms),
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
