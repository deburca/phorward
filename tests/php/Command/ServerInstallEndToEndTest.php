<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;

/**
 * bin/phorward server:install --apply as a real process. The stand-in for ssh runs each script on this machine
 * (as `sh -c`, with the payload on standard input), so the scripts really execute: into a scratch prefix, as the
 * current user, without sudo. The same scripts run on a server with sudo; that part is covered where a server is
 * available. The scripts use `mv -T`, which Linux has and macOS does not, so this runs on Linux only.
 */
final class ServerInstallEndToEndTest extends TestCase {

  use TempDirTrait;

  protected function setUp(): void {
    parent::setUp();
    exec('mv --help 2>&1 | grep -q -e " -T"', $unused, $code);
    if ($code !== 0) {
      $this->markTestSkipped('This mv has no -T option (GNU coreutils only).');
    }
  }

  private function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function install(array $args = [], array $env = []): array {
    $project = $this->tmp . '/project';
    if (!is_dir($project)) {
      $this->write('project/composer.json', json_encode(['name' => 'acme/e2e-lib']));
      $this->write('project/composer.lock', '{}');
    }
    $ssh = $this->write('shim/ssh', "#!/bin/sh\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nshift\nexec sh -c \"\$1\"\n");
    chmod($ssh, 0755);
    $podman = $this->write('shim/podman', "#!/bin/sh\nexit 0\n");
    chmod($podman, 0755);
    if (!is_dir($this->tmp . '/state')) {
      mkdir($this->tmp . '/state');
    }
    $base = array_merge([PHP_BINARY, $this->root() . '/bin/phorward', 'server:install', 'admin@local', '--apply', '--no-sudo',
      '--user', trim((string) shell_exec('id -un')),
      '--prefix', $this->tmp . '/opt/phorward', '--policy-file', $this->tmp . '/etc/phorward/policy.json',
      '--results-dir', $this->tmp . '/var/results'], $args);
    $process = proc_open($base, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $env + [
      'PATH' => $this->tmp . '/shim:' . getenv('PATH'), 'HOME' => $this->tmp . '/home', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg',
      'PHORWARD_ENGINE' => $this->root() . '/tests/fakes/fake-engine', 'FAKE_STATE' => $this->tmp . '/state',
    ]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($process), $out, $err];
  }

  public function testKitAndPolicyAreInstalled(): void {
    [$code, $out, $err] = $this->install(['--skip-build']);
    $this->assertSame(0, $code, $out . $err);
    $prefix = $this->tmp . '/opt/phorward';
    $this->assertTrue(is_link($prefix), 'the prefix is a link to the installed release');
    $this->assertMatchesRegularExpression('#/opt/phorward\.d/[0-9a-f]{12}$#', readlink($prefix));
    foreach (['server/phorward-run', 'server/build-image', 'server/smoke-test', 'server/authorized-key'] as $tool) {
      $this->assertTrue(is_executable("$prefix/$tool"), "$tool is installed and executable");
    }
    $this->assertFileExists("$prefix/profiles/drupal-module/manifest.json");
    $this->assertFileExists("$prefix/profiles/php-library/Dockerfile");
    $this->assertDirectoryDoesNotExist("$prefix/server/__pycache__");

    $policy = json_decode((string) file_get_contents($this->tmp . '/etc/phorward/policy.json'), TRUE);
    $this->assertSame(['allowed_profiles' => ['php-library'], 'results_dir' => $this->tmp . '/var/results'], $policy);
    $this->assertSame('0750', substr(sprintf('%o', fileperms($this->tmp . '/var/results')), -4));
    $this->assertStringContainsString('policy created', $out);
    $this->assertStringContainsString('installed', $out);
  }

  public function testTheInstalledKitWorks(): void {
    $this->install(['--skip-build']);
    exec('PHORWARD_POLICY=' . escapeshellarg($this->tmp . '/etc/phorward/policy.json') . ' PHORWARD_ENGINE='
      . escapeshellarg($this->root() . '/tests/fakes/fake-engine') . ' FAKE_STATE=' . escapeshellarg($this->tmp . '/state')
      . ' ' . escapeshellarg($this->tmp . '/opt/phorward/server/phorward-run') . ' info --protocol 1', $lines, $code);
    $this->assertSame(0, $code);
    $this->assertSame(1, json_decode(implode("\n", $lines), TRUE)['protocol']);
  }

  public function testRunningItAgainChangesNothing(): void {
    $this->install(['--skip-build']);
    $link = readlink($this->tmp . '/opt/phorward');
    file_put_contents($this->tmp . '/etc/phorward/policy.json', '{"allowed_profiles": ["php-library", "drupal-module"], "slots": 1}');
    [$code, $out] = $this->install(['--skip-build']);
    $this->assertSame(0, $code, $out);
    $this->assertSame($link, readlink($this->tmp . '/opt/phorward'));
    $this->assertStringContainsString('is already installed', $out);
    $this->assertStringContainsString('policy kept as it is', $out);
    $this->assertSame('{"allowed_profiles": ["php-library", "drupal-module"], "slots": 1}', file_get_contents($this->tmp . '/etc/phorward/policy.json'),
      'an existing policy is never overwritten');
    $this->assertCount(1, glob($this->tmp . '/opt/phorward.d/*'), 'no second release was made');
  }

  public function testAPolicyThatLacksTheProfileIsFlagged(): void {
    $this->install(['--skip-build']);
    file_put_contents($this->tmp . '/etc/phorward/policy.json', '{"allowed_profiles": ["drupal-module"]}');
    [, $out] = $this->install(['--skip-build']);
    $this->assertStringContainsString('note: php-library is not in allowed_profiles', $out);
  }

  public function testAChangedKitMakesANewReleaseAndSwitchesTheLink(): void {
    $this->install(['--skip-build']);
    $first = readlink($this->tmp . '/opt/phorward');
    // Pretend an older release was installed: point the link at a different directory, as an upgrade would find it.
    mkdir($this->tmp . '/opt/phorward.d/old');
    unlink($this->tmp . '/opt/phorward');
    symlink($this->tmp . '/opt/phorward.d/old', $this->tmp . '/opt/phorward');
    [$code, $out] = $this->install(['--skip-build']);
    $this->assertSame(0, $code, $out);
    $this->assertSame($first, readlink($this->tmp . '/opt/phorward'), 'the link is switched back to the current release');
    $this->assertDirectoryExists($this->tmp . '/opt/phorward.d/old', 'the previous release is left in place');
  }

  public function testARealDirectoryAtThePrefixIsRefusedBeforeAnythingChanges(): void {
    mkdir($this->tmp . '/opt/phorward', 0777, TRUE);
    [$code, $out] = $this->install(['--skip-build']);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('exists and is a directory', $out);
    $this->assertStringContainsString('Nothing was changed.', $out);
    $this->assertFileDoesNotExist($this->tmp . '/etc/phorward/policy.json');
  }

  public function testAMissingUserIsRefused(): void {
    [$code, $out] = $this->install(['--skip-build', '--user', 'nosuchuser12345']);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('the user nosuchuser12345 does not exist', $out);
    $this->assertDirectoryDoesNotExist($this->tmp . '/opt');
  }

  public function testTheImageIsBuiltFromAnArchiveOfTheProject(): void {
    $this->write('project/composer.json', json_encode(['name' => 'acme/e2e-lib']));
    $this->write('project/composer.lock', '{}');
    $this->write('project/src/A.php', "<?php\n");
    $this->write('project/.env', "SECRET=1\n");
    [$code, $out, $err] = $this->install(['--skip-smoke']);
    $this->assertSame(0, $code, $out . $err);
    $calls = array_map('json_decode', file($this->tmp . '/state/calls.jsonl', FILE_IGNORE_NEW_LINES));
    $build = array_values(array_filter($calls, fn($c) => $c[0] === 'build'));
    $this->assertCount(1, $build);
    $args = $build[0];
    $this->assertContains('PROJECT_PACKAGE=acme/e2e-lib', $args);
    $this->assertContains('PROJECT_NAME=e2e_lib', $args);
    $this->assertContains('PHP_VERSION=8.3', $args);
    $this->assertContains('phorward:e2e_lib-php8.3', $args);
  }

}
