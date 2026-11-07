<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;

/**
 * bin/phorward run as a real process. A stand-in for ssh hands the request to the real server/phorward-run (as a
 * forced command would), with a fake container engine behind it, so the client, its tar archive, the wrapper, the
 * protocol and the exit statuses are all the real ones.
 */
final class RunEndToEndTest extends TestCase {

  use TempDirTrait;

  private function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function cli(array $args = [], array $env = [], array $policy = []): array {
    $project = $this->tmp . '/project';
    if (!is_dir($project)) {
      mkdir($project);
      file_put_contents($project . '/smoke.info.yml', "name: Smoke\n");
      file_put_contents($project . '/composer.json', '{"name": "phorward/smoke"}');
      mkdir($project . '/src');
      file_put_contents($project . '/src/A.php', "<?php\n");
      file_put_contents($project . '/.env', "SECRET=1\n");
    }
    $shim = $this->write('shim/ssh', <<<'SH'
#!/bin/sh
# Stands in for ssh. SHIM_MODE selects a failure; otherwise it runs phorward-run as a forced command would.
case "$SHIM_MODE" in
  refused) echo "ssh: connect to host box port 22: Connection refused" >&2; exit 255 ;;
  nocommand) echo "bash: line 1: phorward-run: command not found" >&2; exit 127 ;;
  noise) echo "Welcome to the server"; exit 0 ;;
esac
while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do shift; done
shift
shift
SSH_ORIGINAL_COMMAND="$1" exec python3 -I "$WRAPPER"
SH);
    chmod($shim, 0755);
    if (!is_dir($this->tmp . '/state')) {
      mkdir($this->tmp . '/state', 0777, TRUE);
    }
    if (!is_file($this->tmp . '/policy.json') || $policy !== []) {
      $this->write('policy.json', json_encode($policy + [
        'results_dir' => $this->tmp . '/results', 'lock_dir' => $this->tmp . '/locks', 'tmp_dir' => $this->tmp,
        'allowed_profiles' => ['drupal-module'],
      ]));
    }
    $env += [
      'PATH' => $this->tmp . '/shim:' . getenv('PATH'), 'HOME' => $this->tmp . '/home', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg',
      'PHORWARD_HOST' => 'box', 'PHORWARD_BACKOFF' => '0.1', 'WRAPPER' => $this->root() . '/server/phorward-run',
      'PHORWARD_ENGINE' => $this->root() . '/tests/fakes/fake-engine', 'PHORWARD_POLICY' => $this->tmp . '/policy.json',
      'FAKE_STATE' => $this->tmp . '/state',
    ];
    $process = proc_open(array_merge([PHP_BINARY, $this->root() . '/bin/phorward', 'run'], $args),
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($process), $out, $err];
  }

  public function testAPassingRun(): void {
    [$code, $out, $err] = $this->cli();
    $this->assertSame(0, $code, $out . $err);
    $this->assertStringContainsString('verdict: passed', $out);
    foreach (['lint', 'phpcs', 'phpstan', 'phpunit'] as $check) {
      $this->assertMatchesRegularExpression("/^  $check\\s+passed/m", $out);
    }
    $this->assertStringContainsString('server: protocol 1, kit 0.1.0; php 8.3.36, phpcs 4.0.4, phpstan 2.3.5, phpunit 11.5.57', $out);
  }

  public function testAFailedCheckIsExitOneWithItsOutput(): void {
    [$code, $out] = $this->cli([], ['FAKE_BEHAVIOR' => json_encode(['phpcs' => ['rc' => 2, 'output' => "FOUND 3 ERRORS AFFECTING 2 LINES\n"]])]);
    $this->assertSame(1, $code);
    $this->assertMatchesRegularExpression('/^  phpcs\s+FAILED/m', $out);
    $this->assertStringContainsString("--- phpcs output\nFOUND 3 ERRORS AFFECTING 2 LINES", $out);
    $this->assertStringContainsString('verdict: failed', $out);
  }

  public function testTerminalEscapesInACheckOutputDoNotReachTheTerminal(): void {
    [, $out] = $this->cli([], ['FAKE_BEHAVIOR' => json_encode(['lint' => ['rc' => 1, 'output' => "\e[2J\e[Hgotcha\e]0;owned\x07\n"]])]);
    $this->assertStringNotContainsString("\e", $out);
    $this->assertStringNotContainsString("\x07", $out);
    $this->assertStringContainsString('gotcha', $out);
  }

  public function testJsonCarriesTheServersDocumentAndTheClientsAccount(): void {
    [$code, $out] = $this->cli(['--format', 'json']);
    $this->assertSame(0, $code);
    $json = json_decode($out, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['passed', 1, 'drupal-module', 'box'], [$json['verdict'], $json['protocol'], $json['profile'], $json['client']['host']]);
    $this->assertSame(['lint', 'phpcs', 'phpstan', 'phpunit'], array_column($json['checks'], 'name'));
    $this->assertSame(['passed', 0, 1], [$json['client']['outcome'], $json['client']['exit_code'], $json['client']['attempts']]);
  }

  public function testJunit(): void {
    [$code, $out] = $this->cli(['--format', 'junit'], ['FAKE_BEHAVIOR' => json_encode(['phpstan' => ['rc' => 1, 'output' => "[ERROR] 2 errors\n"]])]);
    $this->assertSame(1, $code);
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML($out));
    $this->assertSame('1', $xml->getElementsByTagName('testsuite')->item(0)->getAttribute('failures'));
    $this->assertSame('4', $xml->getElementsByTagName('testsuite')->item(0)->getAttribute('tests'));
  }

  public function testSelectedChecksAndTests(): void {
    $this->cli(['--tests', 'src/A.php']);
    [$code, $out] = $this->cli(['--checks', 'lint,phpunit', '--tests', 'src/A.php']);
    $this->assertSame(0, $code, $out);
    $this->assertDoesNotMatchRegularExpression('/^  phpcs\s/m', $out);
    $this->assertDoesNotMatchRegularExpression('/^  phpstan\s/m', $out);
    $this->assertMatchesRegularExpression('/^  phpunit\s+passed/m', $out);
  }

  public function testATestPathThatIsNotInTheTreeIsRefusedByTheServerAsAConfigurationError(): void {
    [$code, $out] = $this->cli(['--tests', 'tests/Missing.php']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('test path not in the tree', $out);
    $this->assertStringContainsString('Check the options you gave, for example --tests', $out);
  }

  public function testTheSecretFileInTheProjectNeverArrivesAtTheServer(): void {
    [$code] = $this->cli(['--format', 'json']);
    $this->assertSame(0, $code);
    $calls = file_get_contents($this->tmp . '/state/calls.jsonl');
    $this->assertStringNotContainsString('SECRET=1', (string) $calls);
    $this->assertSame([], glob($this->tmp . '/phorward-run-*/tree/.env') ?: []);
  }

  public function testAResourceLimitIsInfrastructureNotFailureAndIsNotRetried(): void {
    [$code, $out, $err] = $this->cli(['--limits-timeout', '2'], ['FAKE_BEHAVIOR' => json_encode(['phpcs' => ['sleep' => 30]])]);
    $this->assertSame(70, $code, $out . $err);
    $this->assertStringContainsString('no verdict', $out);
    $this->assertStringContainsString('the run exceeded its wall-clock limit', $out . json_encode($out));
    $this->assertStringContainsString('would hit it again', $out);
    $this->assertStringNotContainsString('Attempt 1 failed', $err, 'a resource limit is not retried');
  }

  public function testOutOfMemoryIsInfrastructureToo(): void {
    [$code, $out] = $this->cli([], ['FAKE_BEHAVIOR' => json_encode(['phpunit' => ['rc' => 137, 'oom' => TRUE]])]);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('oom_killed', $out);
    $this->assertStringNotContainsString('problems in the code', $out);
  }

  public function testABusyServerIsRetriedThenReportedAs75(): void {
    $locks = $this->tmp . '/locks';
    mkdir($locks, 0777, TRUE);
    $held = fopen($locks . '/slot-0.lock', 'c');
    flock($held, LOCK_EX);
    [$code, $out, $err] = $this->cli(['--wait', '2'], [], ['slots' => 1, 'slot_wait' => 1]);
    flock($held, LOCK_UN);
    fclose($held);
    $this->assertSame(75, $code, $out . $err);
    $this->assertStringContainsString('The server is busy', $err);
    $this->assertStringContainsString('every run slot is taken', $out);
  }

  public function testAServerThatIsBusyAtFirstThenFreesUpPasses(): void {
    $locks = $this->tmp . '/locks';
    mkdir($locks, 0777, TRUE);
    $holder = proc_open([PHP_BINARY, '-r', '$f = fopen($argv[1], "c"); flock($f, LOCK_EX); echo "held\n"; sleep(3);', $locks . '/slot-0.lock'], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    [$code, $out, $err] = $this->cli(['--wait', '30', '--backoff', '1'], [], ['slots' => 1, 'slot_wait' => 1]);
    proc_close($holder);
    $this->assertSame(0, $code, $out . $err);
    $this->assertStringContainsString('The server is busy', $err);
  }

  public function testAnUnreachableServerIsRetriedThenReportedAsInfrastructure(): void {
    [$code, $out, $err] = $this->cli([], ['SHIM_MODE' => 'refused', 'PHORWARD_RETRIES' => '2']);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('Attempt 1 failed (ssh could not connect: ssh: connect to host box port 22: Connection refused)', $err);
    $this->assertStringContainsString('Attempt 2 failed', $err);
    $this->assertStringContainsString('(3 attempts', $out);
    $this->assertStringContainsString('phorward doctor', $out);
  }

  public function testNoPhorwardRunForTheKeyIsAConfigurationErrorAndNotRetried(): void {
    [$code, $out, $err] = $this->cli([], ['SHIM_MODE' => 'nocommand']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('the server has no phorward-run for this key', $out);
    $this->assertStringNotContainsString('Attempt', $err);
  }

  public function testNoiseInsteadOfAResultIsRetried(): void {
    [$code, , $err] = $this->cli([], ['SHIM_MODE' => 'noise', 'PHORWARD_RETRIES' => '1']);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('Attempt 1 failed (the server did not answer with a phorward result: Welcome to the server)', $err);
  }

  public function testAPolicyThatDoesNotAllowTheProfile(): void {
    [$code, $out] = $this->cli([], [], ['allowed_profiles' => ['php-library']]);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('profile_not_allowed', $out);
    $this->assertStringContainsString('allowed_profiles', $out);
  }

}
