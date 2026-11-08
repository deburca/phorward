<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Ssh\TransportResult;
use Phorward\Tests\Fake\SequenceTransport;
use Phorward\Tests\TempDirTrait;

final class RunCommandTest extends TestCase {

  use TempDirTrait;

  private ?SequenceTransport $transport = NULL;
  /** @var float[] */
  private array $slept = [];

  protected function setUp(): void {
    $this->write('project/thing.info.yml', "name: Thing\n");
    $this->write('project/composer.json', '{"name": "acme/thing"}');
    $this->write('project/src/A.php', "<?php\n");
    $this->write('project/.env', "SECRET=1\n");
    $this->write('project/.git/HEAD', "ref\n");
    $this->write('project/vendor/x.php', "<?php\n");
  }

  /**
   * @param TransportResult[] $replies
   * @return array{0: int, 1: string, 2: string}
   */
  private function execute(array $args, array $replies, array $env = [], ?string $cwd = NULL): array {
    $this->transport = new SequenceTransport($replies);
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env += ['PHORWARD_HOST' => 'box', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $app = new Application(Schema::standard(), new Output($out, $err), fn(string $host, int $timeout) => $this->transport, NULL,
      function (float $seconds) { $this->slept[] = $seconds; });
    $code = $app->run(array_merge(['run'], $args), $env, $cwd ?? $this->tmp . '/project');
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  private function reply(string $verdict, ?string $code = NULL, array $checks = []): TransportResult {
    $doc = ['protocol' => 1, 'kit_version' => '0.1.0', 'verdict' => $verdict, 'image' => 'phorward:thing-php8.3',
      'tool_versions' => ['php' => '8.3.36'], 'limits' => ['requested' => [], 'applied' => [], 'clamped' => []],
      'checks' => $checks ?: [['name' => 'lint', 'status' => $verdict === 'passed' ? 'passed' : 'failed', 'duration_ms' => 12, 'output' => $verdict === 'failed' ? "oops\n" : '']]];
    if ($code !== NULL) {
      $doc['error'] = ['code' => $code, 'message' => 'm'];
    }
    return new TransportResult($verdict === 'passed' ? 0 : ($verdict === 'failed' ? 1 : ($code === 'busy' ? 75 : 70)), json_encode($doc), '');
  }

  private function members(string $tar): array {
    $file = $this->write('payload.tar', $tar);
    exec('tar -tf ' . escapeshellarg($file), $names);
    return array_map(fn($n) => preg_replace('#^\./#', '', $n), $names);
  }

  public function testAPassingRun(): void {
    [$code, $out, $err] = $this->execute([], [$this->reply('passed')]);
    $this->assertSame(0, $code, $out . $err);
    $this->assertSame('', $err);
    $this->assertStringContainsString('verdict: passed', $out);
    $this->assertSame(['phorward-run run --protocol 1 --profile drupal-module --module thing'], $this->transport->scripts());
  }

  public function testTheTreeSentIsTheProjectWithoutWhatMustNotLeave(): void {
    $this->execute([], [$this->reply('passed')]);
    $names = $this->members($this->transport->calls[0][1]);
    $this->assertContains('src/A.php', $names);
    $this->assertContains('composer.json', $names);
    foreach ($names as $name) {
      $this->assertStringNotContainsString('.env', $name);
      $this->assertStringNotContainsString('.git/', $name);
      $this->assertStringNotContainsString('vendor/', $name);
    }
  }

  public function testConfigurationBecomesTheRequest(): void {
    $this->write('project/phorward.json', json_encode([
      'php' => '8.5', 'checks' => ['lint', 'phpunit'], 'database' => 'sqlite',
      'limits' => ['memory' => '2g', 'cpus' => 1.5, 'pids' => 128, 'timeout' => 240],
      'sync' => ['exclude' => ['src'], 'include_vendor' => TRUE],
    ]));
    $this->execute(['--tests', 'tests/AThingTest.php, tests/Sub/BTest.php'], [$this->reply('passed')]);
    $this->assertSame('phorward-run run --protocol 1 --profile drupal-module --php 8.5 --module thing --checks lint,phpunit --db sqlite '
      . '--tests tests/AThingTest.php,tests/Sub/BTest.php --memory 2g --cpus 1.5 --pids 128 --timeout 240', $this->transport->scripts()[0]);
    $names = $this->members($this->transport->calls[0][1]);
    $this->assertNotContains('src/A.php', $names, 'sync.exclude applies');
    $this->assertContains('vendor/x.php', $names, 'sync.include_vendor applies');
    $this->assertNotContains('.env', $names, 'secrets still never go');
  }

  public function testFlagsOverrideTheProjectFile(): void {
    $this->write('project/phorward.json', json_encode(['php' => '8.5']));
    $this->execute(['--php', '8.4', '--checks', 'lint'], [$this->reply('passed')]);
    $this->assertSame('phorward-run run --protocol 1 --profile drupal-module --php 8.4 --module thing --checks lint', $this->transport->scripts()[0]);
  }

  public function testExitStatusKeepsFailedApartFromBroken(): void {
    $cases = [
      [$this->reply('passed'), 0],
      [$this->reply('failed'), 1],
      [$this->reply('error', 'bad_request'), 2],
      [$this->reply('error', 'timeout'), 70],
      [new TransportResult(127, '', "bash: phorward-run: command not found\n"), 2],
    ];
    foreach ($cases as [$reply, $expected]) {
      [$code] = $this->execute([], [$reply]);
      $this->assertSame($expected, $code);
    }
  }

  public function testAFailedCheckShowsItsOutput(): void {
    [$code, $out] = $this->execute([], [$this->reply('failed')]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString('--- lint output', $out);
    $this->assertStringContainsString('oops', $out);
    $this->assertStringContainsString('verdict: failed', $out);
  }

  public function testTransientFailuresAreRetriedAndReportedOnStderr(): void {
    $ssh = new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n");
    [$code, $out, $err] = $this->execute(['--format', 'json'], [$ssh, $ssh, $this->reply('passed')]);
    $this->assertSame(0, $code);
    $this->assertSame([2.0, 4.0], $this->slept);
    $this->assertStringContainsString('phorward: Attempt 1 failed (ssh could not connect: ssh: connect to host box port 22: Connection refused); trying again in 2 s.', $err);
    $json = json_decode($out, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(3, $json['client']['attempts'], 'stdout stays one JSON document while progress goes to stderr');
  }

  public function testRetriesAndBackoffComeFromConfiguration(): void {
    $ssh = new TransportResult(255, '', "refused\n");
    [$code, $out, $err] = $this->execute([], [$ssh, $ssh], ['PHORWARD_RETRIES' => '1', 'PHORWARD_BACKOFF' => '0.5']);
    $this->assertSame(70, $code);
    $this->assertSame([0.5], $this->slept);
    $this->assertCount(2, $this->transport->calls);
    $this->assertStringContainsString('no verdict', $out);
    $this->assertStringContainsString('says nothing about the code', $out);
    $this->assertStringContainsString('phorward doctor', $out);
  }

  public function testABusyServerEndsAs75(): void {
    $busy = $this->reply('error', 'busy');
    [$code, $out] = $this->execute([], array_fill(0, 10, $busy), ['PHORWARD_WAIT' => '5']);
    $this->assertSame(75, $code);
    $this->assertSame([2.0], $this->slept);
    $this->assertStringContainsString('the server is busy', $out);
  }

  public function testOutputFormats(): void {
    [, $json] = $this->execute(['--format', 'json'], [$this->reply('failed')]);
    $this->assertSame('failed', json_decode($json, TRUE)['verdict']);
    [, $junit] = $this->execute(['--format', 'junit'], [$this->reply('failed')]);
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML($junit));
    $this->assertSame(1, $xml->getElementsByTagName('failure')->length);
    [, $human] = $this->execute(['--format', 'human'], [$this->reply('passed')]);
    $this->assertStringContainsString('verdict: passed', $human);
    [$code] = $this->execute(['--format', 'yaml'], []);
    $this->assertSame(2, $code, 'an unknown format is refused before anything is sent');
    $this->assertSame([], $this->transport->calls);
  }

  public function testWhenThereIsNothingToRunOnItSaysSo(): void {
    [$code, , $err] = $this->execute([], [], ['PHORWARD_HOST' => '']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('No server is configured', $err);
    $this->assertSame([], $this->transport->calls);

    mkdir($this->tmp . '/empty');
    [$code, , $err] = $this->execute([], [], [], $this->tmp . '/empty');
    $this->assertSame(2, $code);
    $this->assertStringContainsString('Cannot tell which project this is', $err);
  }

  public function testBadArgumentsAreRefusedBeforeAnythingIsSent(): void {
    foreach ([['extra'], ['--tests'], ['--tests', '../etc/passwd'], ['--tests', '/etc/passwd'], ['--tests', 'a b'], ['--checks', 'phpmd'], ['--paths'], ['--paths', '../etc/passwd'], ['--paths', 'a b'], ['--paths', 'a//b.php'],
      ['--database', 'oracle'], ['--limits-memory', 'lots'], ['--bogus', '1'], ['--host', '-oProxyCommand=id']] as $args) {
      [$code] = $this->execute($args, []);
      $this->assertSame(2, $code, json_encode($args));
      $this->assertSame([], $this->transport->calls, json_encode($args));
    }
  }

  public function testTheServerCannotWriteToTheTerminal(): void {
    $hostile = ['protocol' => 1, 'verdict' => 'failed', 'kit_version' => "0.1\e[2J", 'checks' => [['name' => "lint\e]0;x\x07", 'status' => 'failed', 'output' => "\e[2Jcleared\n"]]];
    [, $out] = $this->execute([], [new TransportResult(1, json_encode($hostile), '')]);
    $this->assertStringNotContainsString("\e", $out);
    $this->assertStringNotContainsString("\x07", $out);
    $this->assertStringContainsString('cleared', $out);
  }

  public function testHelp(): void {
    [$code, $out] = $this->execute(['--help'], []);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('run', $out);
  }

}
