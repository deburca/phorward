<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Ssh\TransportResult;
use Phorward\Tests\Fake\SequenceTransport;
use Phorward\Tests\GitRepoTrait;
use Phorward\Tests\TempDirTrait;

final class VerifyCommandTest extends TestCase {

  use TempDirTrait;
  use GitRepoTrait;

  private ?SequenceTransport $transport = NULL;

  protected function setUp(): void {
    $this->initRepo('project');
    $this->write('project/thing.info.yml', "name: Thing\ntype: module\n");
    $this->write('project/composer.json', '{"name": "acme/thing"}');
    $this->write('project/src/Thing.php', "<?php\nclass Thing {}\n");
    $this->write('project/tests/src/Unit/ThingTest.php', "<?php\n// tests Thing\n");
    $this->commitAll('project', 'first');
    $this->git('project', 'checkout', '-q', '-b', 'work');
  }

  /** A change that touches a source file and its test. */
  private function change(): void {
    $this->write('project/src/Thing.php', "<?php\nclass Thing { function a() {} }\n");
    $this->write('project/tests/src/Unit/ThingTest.php', "<?php\n// tests Thing, now more\n");
  }

  /**
   * @param array<int, array{0: string, 1: string, 2?: string}> $checks name, status and output
   */
  private function reply(array $checks): TransportResult {
    $failed = (bool) array_filter($checks, fn($c) => $c[1] === 'failed');
    $doc = ['protocol' => 1, 'kit_version' => '0.1.0', 'verdict' => $failed ? 'failed' : 'passed', 'image' => 'phorward:thing-php8.3',
      'tool_versions' => ['php' => '8.3.36'], 'limits' => ['requested' => [], 'applied' => [], 'clamped' => []],
      'checks' => array_map(fn($c) => ['name' => $c[0], 'status' => $c[1], 'duration_ms' => 1500, 'output' => $c[2] ?? ''], $checks)];
    return new TransportResult($failed ? 1 : 0, json_encode($doc), '');
  }

  private function good(string ...$names): TransportResult {
    return $this->reply(array_map(fn($n) => [$n, 'passed'], $names));
  }

  /**
   * @param TransportResult[] $replies
   * @return array{0: int, 1: string, 2: string}
   */
  private function verify(array $args, array $replies, array $env = []): array {
    $this->transport = new SequenceTransport($replies);
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env += ['PHORWARD_HOST' => 'box', 'PHORWARD_RETRIES' => '0', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $app = new Application(Schema::standard(), new Output($out, $err), fn(string $host, int $timeout) => $this->transport, NULL, function (float $s) {});
    $code = $app->run(array_merge(['verify'], $args), $env, $this->tmp . '/project');
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  public function testAPassingChangeRunsTheGatesOnTheOldestAndNewestPhp(): void {
    $this->change();
    [$code, $out, $err] = $this->verify([], [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')]);
    $this->assertSame(0, $code, $out . $err);
    $this->assertSame([
      'phorward-run run --protocol 1 --profile drupal-module --php 8.3 --module thing --checks lint,phpcs,phpstan',
      'phorward-run run --protocol 1 --profile drupal-module --php 8.5 --module thing --checks phpunit --tests tests/src/Unit/ThingTest.php',
    ], $this->transport->scripts());
    foreach (['changes', 'syntax', 'phpcs', 'phpstan', 'phpunit', 'tests', 'security'] as $gate) {
      $this->assertMatchesRegularExpression("/^  $gate +passed/m", $out);
    }
    $this->assertStringContainsString('verdict: passed', $out);
    $this->assertStringContainsString('does not show the logic or the tests are right', $out);
  }

  public function testTheTreeIsArchivedOnceForBothRuns(): void {
    $this->change();
    $this->verify([], [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')]);
    $this->assertSame($this->transport->calls[0][1], $this->transport->calls[1][1]);
  }

  public function testAFailedGateIsReportedAndTheLaterGatesStillRun(): void {
    $this->change();
    $log = "\e[31mFOUND 2 ERRORS\e[0m\nsrc/Thing.php line 2\n";
    [$code, $out] = $this->verify([], [$this->reply([['lint', 'passed'], ['phpcs', 'failed', $log], ['phpstan', 'passed']]), $this->good('phpunit')]);
    $this->assertSame(1, $code);
    $this->assertMatchesRegularExpression('/^  phpcs +FAILED/m', $out);
    $this->assertMatchesRegularExpression('/^  phpunit +passed/m', $out);
    $this->assertStringContainsString("--- phpcs\nFOUND 2 ERRORS\nsrc/Thing.php line 2", $out);
    $this->assertStringNotContainsString("\e", $out);
    $this->assertStringContainsString('verdict: failed; first failure: phpcs', $out);
  }

  public function testNothingChangedIsAFailureAndSendsNothing(): void {
    [$code, $out] = $this->verify([], []);
    $this->assertSame(1, $code);
    $this->assertSame([], $this->transport->calls);
    $this->assertMatchesRegularExpression('/^  changes +FAILED/m', $out);
    $this->assertStringContainsString('Nothing was implemented', $out);
    $this->assertMatchesRegularExpression('/^  phpunit +skipped/m', $out);
  }

  public function testAnInfrastructureFailureIsNotAGateFailure(): void {
    $this->change();
    $down = new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n");
    [$code, $out] = $this->verify([], [$down, $this->good('phpunit')]);
    $this->assertSame(70, $code);
    $this->assertMatchesRegularExpression('/^  phpcs +NOT RUN/m', $out);
    $this->assertMatchesRegularExpression('/^  phpunit +passed/m', $out);
    $this->assertStringContainsString('verdict: incomplete', $out);
    $this->assertStringContainsString('says nothing about the code', $out);
  }

  public function testAFailedGateOutranksAnInfrastructureFailure(): void {
    $this->change();
    $down = new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n");
    [$code] = $this->verify([], [$down, $this->reply([['phpunit', 'failed', "1 failure\n"]])]);
    $this->assertSame(1, $code);
  }

  public function testABusyServerGivesTheBusyStatusWhenNothingFailed(): void {
    $this->change();
    $busy = new TransportResult(75, json_encode(['protocol' => 1, 'verdict' => 'error', 'error' => ['code' => 'busy', 'message' => 'all slots taken']]), '');
    [$code] = $this->verify([], [$busy, $this->good('phpunit')], ['PHORWARD_WAIT' => '0']);
    $this->assertSame(75, $code);
  }

  public function testAServerSideSetupProblemGivesTheUsageStatus(): void {
    $this->change();
    $noImage = new TransportResult(2, json_encode(['protocol' => 1, 'verdict' => 'error', 'error' => ['code' => 'no_image', 'message' => 'no image']]), '');
    [$code, $out] = $this->verify([], [$noImage, $noImage]);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('phorward server:install', $out);
  }

  public function testAnAddedDangerousCallFailsTheSecurityGateWhateverTheServerSaid(): void {
    $this->change();
    $this->write('project/src/Thing.php', "<?php\nclass Thing { function a(\$c) { return eval(\$c); } }\n");
    [$code, $out] = $this->verify([], [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')]);
    $this->assertSame(1, $code);
    $this->assertMatchesRegularExpression('/^  security +FAILED/m', $out);
    $this->assertStringContainsString('FAIL src/Thing.php:2: eval()', $out);
  }

  public function testASecurityWarningIsPrintedButDoesNotFail(): void {
    $this->change();
    $this->write('project/src/Thing.php', "<?php\nclass Thing { function a(\$q) { \$q->accessCheck(FALSE); } }\n");
    [$code, $out] = $this->verify([], [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')]);
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('warnings (these do not fail the gate)', $out);
    $this->assertStringContainsString('WARN src/Thing.php:2: accessCheck(FALSE)', $out);
  }

  public function testASuppressedPhpstanFindingFailsTheGateEvenWhenPhpstanPasses(): void {
    $this->change();
    $this->write('project/phpstan-baseline.neon', "parameters:\n  ignoreErrors:\n    -\n      message: '#boom#'\n      path: src/Thing.php\n");
    [$code, $out] = $this->verify([], [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')]);
    $this->assertSame(1, $code);
    $this->assertMatchesRegularExpression('/^  phpstan +FAILED/m', $out);
    $this->assertStringContainsString('phpstan-baseline.neon:4', $out);
  }

  public function testSourceChangedWithoutATestFailsTheTestsGateUnlessAllowed(): void {
    $this->write('project/src/Thing.php', "<?php\nclass Thing { function a() {} }\n");
    $replies = fn() => [$this->good('lint', 'phpcs', 'phpstan'), $this->good('phpunit')];
    [$code, $out] = $this->verify([], $replies());
    $this->assertSame(1, $code);
    $this->assertMatchesRegularExpression('/^  tests +FAILED/m', $out);
    [$code] = $this->verify([], $replies(), ['PHORWARD_GATES_ALLOW_NO_TESTS' => '1']);
    $this->assertSame(0, $code);
  }

  public function testOnlyLocalGatesNeverTouchTheServer(): void {
    $this->change();
    [$code, $out] = $this->verify(['--only', 'security,changes'], []);
    $this->assertSame(0, $code, $out);
    $this->assertSame([], $this->transport->calls);
    $this->assertDoesNotMatchRegularExpression('/^  phpcs/m', $out);
  }

  public function testExplicitTestsReplaceTheSelection(): void {
    $this->change();
    $this->verify(['--only', 'phpunit', '--tests', 'tests/src/Unit/ThingTest.php'], [$this->good('phpunit')]);
    $this->assertStringEndsWith('--checks phpunit --tests tests/src/Unit/ThingTest.php', $this->transport->scripts()[0]);
  }

  public function testNoMatchingTestSkipsPhpunitAndRunsNoSecondRun(): void {
    $this->git('project', 'rm', '-q', 'tests/src/Unit/ThingTest.php');
    $this->commitAll('project', 'remove the only test');
    $this->write('project/other.module', "<?php\n");
    [$code, $out] = $this->verify(['--base', 'HEAD', '--only', 'phpunit,syntax'], [$this->good('lint')]);
    $this->assertSame(0, $code, $out);
    $this->assertMatchesRegularExpression('/^  phpunit +skipped +server +no test matches/m', $out);
    $this->assertCount(1, $this->transport->calls);
  }

  public function testWhenTheOldestAndNewestAreTheSameThereIsOneRun(): void {
    $this->change();
    $this->verify([], [$this->good('lint', 'phpcs', 'phpstan', 'phpunit')], ['PHORWARD_GATES_PHP_OLDEST' => '8.4', 'PHORWARD_GATES_PHP_NEWEST' => '8.4']);
    $this->assertSame(['phorward-run run --protocol 1 --profile drupal-module --php 8.4 --module thing --checks lint,phpcs,phpstan,phpunit --tests tests/src/Unit/ThingTest.php'],
      $this->transport->scripts());
  }

  public function testTheJsonReportNamesTheFirstFailure(): void {
    $this->change();
    [$code, $out] = $this->verify(['--format', 'json'], [$this->reply([['lint', 'passed'], ['phpcs', 'failed', "bad\n"], ['phpstan', 'failed', "worse\n"]]), $this->good('phpunit')]);
    $this->assertSame(1, $code);
    $doc = json_decode($out, TRUE);
    $this->assertSame('failed', $doc['verdict']);
    $this->assertSame(1, $doc['exit_code']);
    $this->assertSame('phpcs', $doc['first_failure']['gate']);
    $this->assertSame("bad", trim($doc['first_failure']['excerpt']));
    $this->assertSame(['syntax', 'phpcs', 'phpstan', 'phpunit'], array_slice(array_column($doc['gates'], 'name'), 1, 4));
    $this->assertSame(['oldest' => '8.3', 'newest' => '8.5'], $doc['php']);
  }

  public function testUsageErrorsAreReportedBeforeAnythingIsSent(): void {
    $this->change();
    [$code, , $err] = $this->verify(['--only', 'lint'], []);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('unknown gate "lint"', $err);
    [$code, , $err] = $this->verify(['--base', 'nowhere'], []);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('"nowhere"', $err);
    [$code, , $err] = $this->verify(['--format', 'junit'], []);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('human or json', $err);
    [$code, , $err] = $this->verify(['extra'], []);
    $this->assertSame(2, $code);
    $this->assertSame([], $this->transport->calls);
  }

  public function testTheGateSettingsComeFromTheProjectFile(): void {
    $this->change();
    $this->write('project/phorward.json', json_encode(['gates' => ['skip' => ['security', 'tests'], 'php' => ['oldest' => '8.4', 'newest' => '8.4'], 'phpunit' => ['max_files' => 1]]]));
    $this->commitAll('project', 'configure');
    $this->git('project', 'checkout', '-q', 'main');
    $this->git('project', 'merge', '-q', '--ff-only', 'work');
    $this->git('project', 'checkout', '-q', '-b', 'work3');
    $this->write('project/src/Thing.php', "<?php\nclass Thing { function b() {} }\n");
    [$code, $out] = $this->verify([], [$this->good('lint', 'phpcs', 'phpstan', 'phpunit')]);
    $this->assertSame(0, $code, $out);
    $this->assertDoesNotMatchRegularExpression('/^  (security|tests) /m', $out);
    $this->assertStringContainsString('--php 8.4', $this->transport->scripts()[0]);
  }

}
