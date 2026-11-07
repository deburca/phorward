<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Tests\Fake\FakeTransport;
use Phorward\Tests\TempDirTrait;
use Phorward\Ssh\TransportResult;

final class DoctorCommandTest extends TestCase {

  use TempDirTrait;

  private const GIB = 1073741824;

  private ?FakeTransport $transport = NULL;

  protected function setUp(): void {
    parent::setUp();
    $this->write('thing.info.yml', "name: Thing\n");
    $this->write('composer.json', '{"name": "acme/thing"}');
  }

  private function info(array $override = []): array {
    return array_replace_recursive([
      'protocol' => 1,
      'kit_version' => '0.1.0',
      'engine' => ['name' => 'podman', 'version' => '5.7.0', 'rootless' => TRUE],
      'policy' => ['max_memory' => '6g', 'max_cpus' => 2, 'max_pids' => 1024, 'max_timeout' => 900, 'slots' => 2],
      'images' => [[
        'profile' => 'drupal-module', 'php' => '8.3', 'tag' => 'phorward:thing-php8.3', 'module_name' => 'thing',
        'lock_sha256' => is_file($this->tmp . '/composer.json') ? hash_file('sha256', $this->tmp . '/composer.json') : '',
        'built' => '2026-11-05',
      ]],
      'host' => ['cpus' => 16, 'memory_bytes' => 96 * self::GIB, 'load_1m' => 0.3, 'disk_free_bytes' => 800 * self::GIB],
      'warnings' => [],
    ], $override);
  }

  private function passedRun(): array {
    return ['protocol' => 1, 'verdict' => 'passed', 'exit_code' => 0, 'duration_ms' => 321,
      'tool_versions' => ['php' => '8.3.36', 'phpcs' => '4.0.4', 'phpunit' => '11.5.57'], 'checks' => []];
  }

  private function reply(array $data, int $exit = 0): TransportResult {
    return new TransportResult($exit, json_encode($data), '');
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function doctor(array $responses, array $env = [], array $args = []): array {
    $this->transport = new FakeTransport($responses);
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env += ['PHORWARD_HOST' => 'box', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $app = new Application(Schema::standard(), new Output($out, $err), fn(string $host, int $timeout) => $this->transport);
    $code = $app->run(array_merge(['doctor'], $args), $env, $this->tmp);
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  private function healthy(array $info = []): array {
    return [
      'phorward-run info' => $this->reply($this->info($info)),
      'phorward-run run' => $this->reply($this->passedRun()),
    ];
  }

  public function testEverythingIsFine(): void {
    [$code, $out] = $this->doctor($this->healthy());
    $this->assertSame(0, $code, $out);
    foreach (['ok    configuration: host box, profile drupal-module, module thing',
      'ok    ssh: connected to box', 'ok    forced command: phorward-run answered, kit 0.1.0',
      'ok    protocol: protocol 1 on both sides', 'ok    container engine: podman 5.7.0, rootless',
      'ok    image: phorward:thing-php8.3, built 2026-11-05', 'ok    image freshness: matches composer.json',
      'ok    end to end: a test run passed in 321 ms; tools: php 8.3.36, phpcs 4.0.4, phpunit 11.5.57',
      'ok    policy: 2 slot(s) of up to 6g and 2 CPU(s), 900s, on a host with 16 CPU(s) and 96 GiB',
      'ok    free disk: 800 GiB free', 'doctor: nothing is wrong'] as $line) {
      $this->assertStringContainsString($line, $out);
    }
    $this->assertStringNotContainsString('fix:', $out);
  }

  public function testTheCommandsSentAreExactlyTheExpectedOnes(): void {
    $this->doctor($this->healthy());
    $this->assertSame([
      'phorward-run info --protocol 1',
      'phorward-run run --protocol 1 --profile drupal-module --php 8.3 --module thing --checks lint',
    ], $this->transport->commands());
    $tar = $this->transport->calls[1][1];
    $this->assertSame('phorward-doctor.php', rtrim(substr($tar, 0, 100), "\0"), 'a one-file tar archive is sent');
    $this->assertNull($this->transport->calls[0][1]);
  }

  public function testNoHostIsConfigured(): void {
    [$code, $out] = $this->doctor([], ['PHORWARD_HOST' => '']);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  configuration: no server is configured', $out);
    $this->assertStringContainsString('fix: Set "host"', $out);
    $this->assertSame([], $this->transport->calls, 'nothing is sent without a host');
  }

  public function testSshCannotConnect(): void {
    [$code, $out] = $this->doctor(['phorward-run' => new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n")]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  ssh: cannot connect to box: ssh: connect to host box port 22: Connection refused', $out);
    $this->assertStringContainsString('ssh -v box', $out);
    $this->assertStringNotContainsString('forced command', $out, 'later checks are not reported on a dead connection');
  }

  public function testSshTimesOut(): void {
    [$code, $out] = $this->doctor(['phorward-run' => new TransportResult(NULL, '', '', TRUE)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  ssh: no answer from box in time', $out);
  }

  public function testNoPhorwardRunForThisKey(): void {
    [$code, $out] = $this->doctor(['phorward-run' => new TransportResult(127, '', "bash: line 1: phorward-run: command not found\n")]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('ok    ssh: connected to box', $out);
    $this->assertStringContainsString('FAIL  forced command: the server has no phorward-run for this key', $out);
    $this->assertStringContainsString('docs/ssh-access.md', $out);
  }

  public function testTheForcedCommandIsSomethingElse(): void {
    [$code, $out] = $this->doctor(['phorward-run' => new TransportResult(0, "Welcome to Ubuntu\n", '')]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  forced command: the server did not answer with phorward-run output: Welcome to Ubuntu', $out);
  }

  public function testTheServerRefusesTheRequest(): void {
    $refusal = ['protocol' => 1, 'verdict' => 'error', 'exit_code' => 70, 'error' => ['code' => 'policy_invalid', 'message' => 'bad policy']];
    [$code, $out] = $this->doctor(['phorward-run' => $this->reply($refusal, 70)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  forced command: the server refused the request: policy_invalid (bad policy)', $out);
    $this->assertStringContainsString('docs/policy.md', $out);
  }

  #[DataProvider('protocolMismatches')]
  public function testProtocolMismatch(int $server, string $fix): void {
    $mismatch = ['protocol' => $server, 'verdict' => 'error', 'exit_code' => 2, 'error' => ['code' => 'protocol_mismatch', 'message' => 'x']];
    [$code, $out] = $this->doctor(['phorward-run' => $this->reply($mismatch, 2)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString("FAIL  protocol: client speaks protocol 1, server speaks $server", $out);
    $this->assertStringContainsString($fix, $out);
  }

  public static function protocolMismatches(): array {
    return [
      'older server' => [0, 'The server is older: update it'],
      'newer server' => [2, 'The server is newer: update this client'],
    ];
  }

  public function testAnInfoReplyWithTheWrongProtocolNumber(): void {
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info(['protocol' => 2]))]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  protocol: client speaks protocol 1, server speaks 2', $out);
  }

  public function testARootfulEngineIsAWarningNotAFailure(): void {
    [$code, $out] = $this->doctor($this->healthy(['engine' => ['name' => 'docker', 'rootless' => FALSE]]));
    $this->assertSame(0, $code, 'warnings do not fail the doctor');
    $this->assertStringContainsString('WARN  container engine: docker 5.7.0, not rootless', $out);
    $this->assertStringContainsString('root on the host', $out);
    $this->assertStringContainsString('doctor: nothing is wrong (1 warning)', $out);
  }

  public function testNoImageForThisProject(): void {
    $info = $this->info();
    $info['images'] = [['profile' => 'php-library', 'php' => '8.3', 'tag' => 'phorward:other-php8.3', 'module_name' => 'other', 'lock_sha256' => '', 'built' => 'x']];
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($info)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  image: no image for profile drupal-module, module thing; the server has phorward:other-php8.3', $out);
    $this->assertStringContainsString('phorward server:install <admin-host> --profile drupal-module', $out);
    $this->assertCount(1, $this->transport->calls, 'no test run without an image');
  }

  public function testNoImagesAtAll(): void {
    $info = $this->info();
    $info['images'] = [];
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($info)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('the server has none', $out);
  }

  public function testTheConfiguredPhpVersionMustBeInstalled(): void {
    [$code, $out] = $this->doctor($this->healthy(), ['PHORWARD_PHP' => '8.5']);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('no image for profile drupal-module, module thing, PHP 8.5', $out);
    $this->assertStringContainsString('--php 8.5', $out);
  }

  public function testTheProjectCannotBeIdentified(): void {
    unlink($this->tmp . '/thing.info.yml');
    unlink($this->tmp . '/composer.json');
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info())]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  image: the profile or module of this project is unknown', $out);
  }

  public function testAStaleImageIsAWarning(): void {
    $this->write('composer.json', '{"name": "acme/thing", "require": {"drupal/key": "^1"}}');
    [$code, $out] = $this->doctor($this->healthy(['images' => [['lock_sha256' => str_repeat('0', 64)]]]));
    $this->assertSame(0, $code);
    $this->assertStringContainsString('WARN  image freshness: composer.json has changed since the image was built', $out);
    $this->assertStringContainsString('Rebuild the image', $out);
  }

  public function testAnImageWithoutAHashIsAWarning(): void {
    [$code, $out] = $this->doctor($this->healthy(['images' => [['lock_sha256' => '']]]));
    $this->assertSame(0, $code);
    $this->assertStringContainsString('WARN  image freshness: the image does not record what it was built from', $out);
  }

  public function testFreshnessIsSkippedForAProfileThisClientDoesNotKnow(): void {
    $this->write('phorward.json', '{"profile": "custom-profile"}');
    $info = $this->info();
    $info['images'][0]['profile'] = 'custom-profile';
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($info), 'phorward-run run' => $this->reply($this->passedRun())]);
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('ok    image freshness: not checked', $out);
  }

  public function testTheEndToEndRunFailsInVariousWays(): void {
    $cases = [
      ['profile_not_allowed', 2, 'allowed_profiles'],
      ['php_not_allowed', 2, 'allowed_php'],
      ['ambiguous_image', 2, 'set "module"'],
      ['policy_invalid', 70, 'docs/policy.md'],
      ['protocol_mismatch', 2, 'update both'],
      ['oom_killed', 70, 'server log'],
    ];
    foreach ($cases as [$errorCode, $exit, $fix]) {
      $run = ['protocol' => 1, 'verdict' => 'error', 'exit_code' => $exit, 'error' => ['code' => $errorCode, 'message' => 'm']];
      [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info()), 'phorward-run run' => $this->reply($run, $exit)]);
      $this->assertSame(1, $code, $errorCode);
      $this->assertStringContainsString("FAIL  end to end: the test run ended in error ($errorCode: m)", $out, $errorCode);
      $this->assertStringContainsString($fix, $out, $errorCode);
    }
  }

  public function testABusyServerIsAWarning(): void {
    $busy = ['protocol' => 1, 'verdict' => 'error', 'exit_code' => 75, 'error' => ['code' => 'busy', 'message' => 'x']];
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info()), 'phorward-run run' => $this->reply($busy, 75)]);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('WARN  end to end: the server is busy', $out);
  }

  public function testTheEndToEndRunTimesOutOrAnswersWithGarbage(): void {
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info()), 'phorward-run run' => new TransportResult(NULL, '', '', TRUE)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  end to end: the test run did not finish in time', $out);
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info()), 'phorward-run run' => new TransportResult(1, "oops\n", '')]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  end to end: the test run gave no usable answer (exit 1): oops', $out);
  }

  public function testAServerCannotMakeTheClientSendAnOddCommand(): void {
    $info = $this->info();
    $info['images'][0]['php'] = '8.3; id';
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($info)]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  end to end: the server reported an image with a PHP version this client will not use', $out);
    $this->assertSame(['phorward-run info --protocol 1'], $this->transport->commands(), 'nothing derived from the reply was sent');
  }

  public function testToolVersionsFromTheServerAreSanitised(): void {
    $run = $this->passedRun();
    $run['tool_versions'] = ["bad name\n" => 'x', 'php' => '8.3.36'];
    [, $out] = $this->doctor(['phorward-run info' => $this->reply($this->info()), 'phorward-run run' => $this->reply($run)]);
    $this->assertStringContainsString('tools: php 8.3.36', $out);
    $this->assertStringNotContainsString('bad name', $out);
  }

  public function testPolicyWarningsAreReportedWithTheSummary(): void {
    [$code, $out] = $this->doctor($this->healthy(['warnings' => [['code' => 'memory_heavy', 'message' => '2 slot(s) x 6g is over half the host\'s memory (96g)']]]));
    $this->assertSame(0, $code);
    $this->assertStringContainsString("WARN  policy: 2 slot(s) x 6g is over half the host's memory (96g)", $out);
    $this->assertStringContainsString('server/hostile-test', $out);
  }

  public function testDiskSpace(): void {
    [$code, $out] = $this->doctor($this->healthy(['host' => ['disk_free_bytes' => 3 * self::GIB]]));
    $this->assertSame(0, $code);
    $this->assertStringContainsString('WARN  free disk: 3 GiB free', $out);
    [$code, $out] = $this->doctor($this->healthy(['host' => ['disk_free_bytes' => 300 * 1048576]]));
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  free disk: 300 MiB free', $out);
  }

  public function testAnOlderServerThatDoesNotReportDisk(): void {
    $info = $this->info();
    unset($info['host']['disk_free_bytes']);
    [$code, $out] = $this->doctor(['phorward-run info' => $this->reply($info), 'phorward-run run' => $this->reply($this->passedRun())]);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('WARN  free disk: the server does not report it', $out);
  }

  public function testJsonOutput(): void {
    [$code, $out] = $this->doctor($this->healthy(['engine' => ['rootless' => FALSE]]), [], ['--output', 'json']);
    $this->assertSame(0, $code);
    $data = json_decode($out, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($data['ok']);
    $this->assertSame('box', $data['host']);
    $names = array_column($data['checks'], 'name');
    $this->assertSame(['configuration', 'ssh', 'forced command', 'protocol', 'container engine', 'image', 'image freshness', 'end to end', 'policy', 'free disk'], $names);
    $engine = $data['checks'][array_search('container engine', $names, TRUE)];
    $this->assertSame('warn', $engine['status']);
    $this->assertNotNull($engine['fix']);
  }

  public function testUsageErrors(): void {
    $this->assertSame(2, $this->doctor([], [], ['extra'])[0]);
    $this->assertSame(2, $this->doctor([], [], ['--output', 'xml'])[0]);
    $this->assertSame(2, $this->doctor([], [], ['--bogus', '1'])[0]);
    $this->assertSame(0, $this->doctor([], [], ['--help'])[0]);
  }

}
