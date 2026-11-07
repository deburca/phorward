<?php

declare(strict_types=1);

namespace Phorward\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;

/**
 * bin/phorward as a real process, so the autoloader lookup and the exit status are covered too.
 */
final class EntryPointTest extends TestCase {

  use TempDirTrait;

  private function cli(array $args, array $env = []): array {
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 3) . '/bin/phorward'], $args);
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp, $env + [
      'PATH' => getenv('PATH'), 'HOME' => $this->tmp . '/home', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg',
    ]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($process), $out, $err];
  }

  public function testVersion(): void {
    [$code, $out] = $this->cli(['--version']);
    $this->assertSame(0, $code);
    $this->assertStringStartsWith('phorward ', $out);
  }

  public function testConfigAsJsonFromTheShell(): void {
    [$code, $out] = $this->cli(['config', '--output', 'json'], ['PHORWARD_HOST' => 'box']);
    $this->assertSame(0, $code);
    $this->assertSame('box', json_decode($out, TRUE)['values']['host']['value']);
  }

  public function testUsageErrorExitStatus(): void {
    [$code, , $err] = $this->cli(['config', '--host', '-oProxyCommand=id']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('host is not valid', $err);
  }

}
