<?php

declare(strict_types=1);

namespace Phorward\Tests\Install;

use PHPUnit\Framework\TestCase;
use Phorward\Config\ConfigException;
use Phorward\Install\AuthorizedKey;
use Phorward\Tests\TempDirTrait;

/**
 * The PHP port must give exactly what server/authorized-key gives, and refuse exactly what it refuses.
 */
final class AuthorizedKeyTest extends TestCase {

  use TempDirTrait;

  private const RUN = '/opt/phorward/server/phorward-run';

  protected function setUp(): void {
    exec('command -v ssh-keygen >/dev/null 2>&1', $unused, $code);
    if ($code !== 0) {
      $this->markTestSkipped('ssh-keygen is not installed.');
    }
  }

  private function key(string $name, string ...$args): string {
    $path = $this->tmp . '/' . $name;
    if (!file_exists($path)) {
      exec('ssh-keygen -q -N "" -C "client one" -f ' . escapeshellarg($path) . ' ' . implode(' ', array_map('escapeshellarg', $args)));
    }
    return $path . '.pub';
  }

  /**
   * @return array{0: int, 1: string}
   */
  private function python(string $pub, array $args = []): array {
    $cmd = array_merge(['python3', '-I', dirname(__DIR__, 3) . '/server/authorized-key', '--public-key', $pub, '--run', self::RUN], $args);
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $code);
    return [$code, implode("\n", $out)];
  }

  public function testSameLineAsThePythonTool(): void {
    $pub = $this->key('ed', '-t', 'ed25519');
    $cases = [
      [[], NULL, []],
      [['--from', '192.0.2.0/24,*.example.org'], '192.0.2.0/24,*.example.org', []],
      [['--env', 'PHORWARD_POLICY=/etc/phorward/policy.json'], NULL, ['PHORWARD_POLICY=/etc/phorward/policy.json']],
      [['--from', '203.0.113.7', '--env', 'A=b', '--env', 'C=d'], '203.0.113.7', ['A=b', 'C=d']],
    ];
    foreach ($cases as [$args, $from, $env]) {
      [$code, $expected] = $this->python($pub, $args);
      $this->assertSame(0, $code);
      $this->assertSame($expected, AuthorizedKey::line($pub, self::RUN, $from, $env));
    }
  }

  public function testOtherKeyTypes(): void {
    foreach ([$this->key('ec', '-t', 'ecdsa', '-b', '256'), $this->key('rsa', '-t', 'rsa', '-b', '3072')] as $pub) {
      [, $expected] = $this->python($pub);
      $this->assertSame($expected, AuthorizedKey::line($pub, self::RUN));
    }
  }

  public function testRefusesWhatThePythonToolRefuses(): void {
    $ed = $this->key('ed', '-t', 'ed25519');
    $private = substr($ed, 0, -4);
    $weak = $this->key('weak', '-t', 'rsa', '-b', '2048');
    $options = $this->tmp . '/options.pub';
    file_put_contents($options, 'command="/bin/sh",no-pty ' . file_get_contents($ed));
    $two = $this->tmp . '/two.pub';
    file_put_contents($two, file_get_contents($ed) . file_get_contents($this->key('ec', '-t', 'ecdsa', '-b', '256')));
    $quote = $this->tmp . '/quote.pub';
    $parts = explode(' ', trim(file_get_contents($ed)));
    file_put_contents($quote, "$parts[0] $parts[1] evil\"comment\n");

    foreach ([$private, $weak, $options, $two, $quote, $this->tmp . '/missing.pub'] as $file) {
      [$code] = $this->python($file);
      $this->assertNotSame(0, $code, $file);
      try {
        AuthorizedKey::line($file, self::RUN);
        $this->fail("Expected a refusal for $file");
      }
      catch (ConfigException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testRefusesBadArguments(): void {
    $pub = $this->key('ed', '-t', 'ed25519');
    foreach ([
      fn() => AuthorizedKey::line($pub, 'relative/phorward-run'),
      fn() => AuthorizedKey::line($pub, '/opt/../phorward-run'),
      fn() => AuthorizedKey::line($pub, '/opt/x" ; id ; "'),
      fn() => AuthorizedKey::line($pub, self::RUN, NULL, ['lower=x']),
      fn() => AuthorizedKey::line($pub, self::RUN, NULL, ['A=$(id)']),
      fn() => AuthorizedKey::line($pub, self::RUN, '1.2.3.4" ,no-restrict'),
    ] as $call) {
      try {
        $call();
        $this->fail('Expected a refusal.');
      }
      catch (ConfigException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
