<?php

declare(strict_types=1);

namespace Phorward\Tests\Ssh;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Ssh\SshTransport;
use Phorward\Tests\TempDirTrait;

/**
 * The transport against stand-ins for ssh, so the exact argument vector, stdin, exit status and timeout are
 * checked without a network.
 */
final class SshTransportTest extends TestCase {

  use TempDirTrait;

  private function fakeSsh(string $body): string {
    $path = $this->write('bin/ssh', "#!/bin/sh\n$body\n");
    chmod($path, 0755);
    return $path;
  }

  public function testTheArgumentVectorIsExactlyWhatIsExpected(): void {
    $ssh = $this->fakeSsh('for a in "$@"; do printf "%s\n" "$a"; done');
    $result = (new SshTransport('box', 7, $ssh))->execute('phorward-run info --protocol 1');
    $this->assertSame(0, $result->exitCode);
    $this->assertFalse($result->timedOut);
    $this->assertSame(['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=7', '--', 'box', 'phorward-run info --protocol 1'],
      explode("\n", rtrim($result->stdout)));
  }

  public function testStdinIsPassedThrough(): void {
    $ssh = $this->fakeSsh('cat');
    $payload = str_repeat("tar bytes \0 \xff\n", 20000);
    $result = (new SshTransport('box', 10, $ssh))->execute('x', $payload);
    $this->assertSame($payload, $result->stdout, 'a large binary input comes back intact, with no deadlock');
  }

  public function testExitStatusAndStderr(): void {
    $ssh = $this->fakeSsh('echo "ssh: connect to host box port 22: Connection refused" >&2; exit 255');
    $result = (new SshTransport('box', 10, $ssh))->execute('x');
    $this->assertSame(255, $result->exitCode);
    $this->assertStringContainsString('Connection refused', $result->stderr);
    $this->assertSame('', $result->stdout);
  }

  public function testTimeout(): void {
    $ssh = $this->fakeSsh('sleep 10');
    $started = microtime(TRUE);
    $result = (new SshTransport('box', 10, $ssh))->execute('x', NULL, 0.5);
    $this->assertTrue($result->timedOut);
    $this->assertNull($result->exitCode);
    $this->assertLessThan(5, microtime(TRUE) - $started);
  }

  public function testNothingIsInterpretedByALocalShell(): void {
    $ssh = $this->fakeSsh('printf "%s" "$#"');
    $result = (new SshTransport('box', 10, $ssh))->execute('a; touch ' . $this->tmp . '/pwned $(id) `id`');
    $this->assertSame('7', $result->stdout, 'the whole command is one argument');
    $this->assertFileDoesNotExist($this->tmp . '/pwned');
  }

  #[DataProvider('badHosts')]
  public function testAHostThatCouldBecomeAnOptionIsRefused(string $host): void {
    $this->expectException(\InvalidArgumentException::class);
    new SshTransport($host);
  }

  public static function badHosts(): array {
    return [['-oProxyCommand=id'], ['--help'], ['box -p 22'], ['box;id'], [''], ['"box"']];
  }

}
