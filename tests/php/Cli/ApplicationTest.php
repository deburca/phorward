<?php

declare(strict_types=1);

namespace Phorward\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Tests\TempDirTrait;

final class ApplicationTest extends TestCase {

  use TempDirTrait;

  /**
   * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
   */
  private function cli(array $argv, array $env = [], ?string $cwd = NULL): array {
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env += ['XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $code = (new Application(Schema::standard(), new Output($out, $err)))->run($argv, $env, $cwd ?? $this->tmp);
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  public function testHelpAndVersion(): void {
    foreach ([[], ['help'], ['--help'], ['-h'], ['config', '--help']] as $argv) {
      [$code, $out] = $this->cli($argv);
      $this->assertSame(0, $code);
      $this->assertStringContainsString('Usage: phorward', $out);
      $this->assertStringContainsString('config', $out);
    }
    foreach ([['version'], ['--version'], ['-V']] as $argv) {
      [$code, $out] = $this->cli($argv);
      $this->assertSame([0, 'phorward ' . Application::VERSION . "\n"], [$code, $out]);
    }
  }

  public function testUnknownCommandIsAUsageError(): void {
    [$code, $out, $err] = $this->cli(['frobnicate']);
    $this->assertSame(2, $code);
    $this->assertSame('', $out);
    $this->assertStringContainsString('Unknown command "frobnicate"', $err);
  }

  public function testConfigShowsValuesAndSources(): void {
    $this->writeJson('phorward.json', ['php' => '8.3']);
    [$code, $out] = $this->cli(['config'], ['PHORWARD_HOST' => 'box']);
    $this->assertSame(0, $code);
    $this->assertMatchesRegularExpression('/^host\s+box\s+environment PHORWARD_HOST$/m', $out);
    $this->assertMatchesRegularExpression('/^php\s+8\.3\s+project file /m', $out);
    $this->assertMatchesRegularExpression('/^retries\s+3\s+default$/m', $out);
    $this->assertStringContainsString('project root: ' . $this->tmp, $out);
  }

  public function testConfigAcceptsSettingsAsFlags(): void {
    [$code, $out] = $this->cli(['config', '--host', 'box', '--sync-include-vendor', '--no-wait']);
    $this->assertSame(2, $code, '--no-wait is false for a number, which is an error');
    [$code, $out] = $this->cli(['config', '--host=box', '--sync-include-vendor', '--limits-memory', '2g']);
    $this->assertSame(0, $code);
    $this->assertMatchesRegularExpression('/^host\s+box\s+flag --host$/m', $out);
    $this->assertMatchesRegularExpression('/^sync\.include_vendor\s+true\s+flag --sync-include-vendor$/m', $out);
  }

  public function testConfigAsJson(): void {
    $this->write('thing.info.yml', 'x');
    $this->writeJson('composer.json', ['name' => 'acme/thing']);
    [$code, $out] = $this->cli(['config', '--output', 'json'], ['PHORWARD_RETRIES' => '4']);
    $this->assertSame(0, $code);
    $data = json_decode($out, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(4, $data['values']['retries']['value']);
    $this->assertSame('environment PHORWARD_RETRIES', $data['values']['retries']['source']);
    $this->assertSame('user', $data['values']['retries']['scope']);
    $this->assertSame('drupal-module', $data['values']['profile']['value']);
    $this->assertSame($this->tmp, $data['project_root']);
    $this->assertSame([], $data['warnings']);
  }

  public function testConfigErrorsGoToStderrWithExitTwo(): void {
    $this->writeJson('phorward.json', ['host' => 'box']);
    [$code, $out, $err] = $this->cli(['config']);
    $this->assertSame(2, $code);
    $this->assertSame('', $out);
    $this->assertStringStartsWith('phorward: ', $err);
    $this->assertStringContainsString('"host" is a user setting', $err);
  }

  public function testConfigRefusesPositionalArgumentsAndBadOutputFormats(): void {
    $this->assertSame(2, $this->cli(['config', 'extra'])[0]);
    $this->assertSame(2, $this->cli(['config', '--output', 'xml'])[0]);
    $this->assertSame(2, $this->cli(['config', '--bogus', '1'])[0]);
  }

  public function testAHostThatLooksLikeAnSshOptionIsRefused(): void {
    [$code, , $err] = $this->cli(['config', '--host=-oProxyCommand=touch /tmp/pwned']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('host is not valid', $err);
  }

  public function testUnknownEnvironmentVariablesAreWarnedAbout(): void {
    [$code, $out] = $this->cli(['config'], ['PHORWARD_HOTS' => 'x']);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('warning: Unknown environment variable PHORWARD_HOTS', $out);
  }

}
