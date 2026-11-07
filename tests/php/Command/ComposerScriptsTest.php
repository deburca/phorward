<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;

/**
 * The adoption path: a project requires phorward as a dev dependency, defines `scripts` aliases in its own
 * composer.json (a dependency's scripts never run), and runs them with `composer run-script`. This installs the
 * package as a copy, the way Composer would from a registry, so it needs the network for symfony/process and is
 * opt-in: PHORWARD_TEST_COMPOSER=1 vendor/bin/phpunit --filter ComposerScripts
 */
final class ComposerScriptsTest extends TestCase {

  use TempDirTrait;

  protected function setUp(): void {
    if (getenv('PHORWARD_TEST_COMPOSER') !== '1') {
      $this->markTestSkipped('Set PHORWARD_TEST_COMPOSER=1 to run the Composer installation test (needs the network).');
    }
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function in(string $dir, array $command, array $env = []): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env + [
      'PATH' => getenv('PATH'), 'HOME' => $this->tmp . '/home', 'COMPOSER_HOME' => $this->tmp . '/composer-home',
    ]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($process), $out, $err];
  }

  public function testScriptAliasesRunThroughVendorBin(): void {
    $root = dirname(__DIR__, 3);
    $project = $this->tmp . '/project';
    mkdir($project);
    file_put_contents($project . '/smoke.info.yml', "name: Smoke\n");
    // A copy of the package without its own vendor/ and VCS data, so the autoloader lookup is the installed one.
    $copy = $this->tmp . '/package';
    mkdir($copy);
    exec('cd ' . escapeshellarg($root) . ' && tar -c --exclude=./vendor --exclude=./.git --exclude=./harness --exclude=./docs/project-management . 2>/dev/null | tar -x -C ' . escapeshellarg($copy));
    $package = json_decode((string) file_get_contents($copy . '/composer.json'), TRUE);
    $package['version'] = '0.1.0';
    file_put_contents($copy . '/composer.json', json_encode($package, JSON_PRETTY_PRINT));

    $composer = [
      'name' => 'phorward/smoke',
      'repositories' => [['type' => 'path', 'url' => $copy, 'options' => ['symlink' => FALSE]]],
      'require-dev' => ['deburca/phorward' => '^0.1'],
      'scripts' => [
        'validate' => 'phorward run',
        'validate:lint' => 'phorward run --checks lint',
        'validate:doctor' => 'phorward doctor',
      ],
      'config' => ['allow-plugins' => new \stdClass()],
    ];
    file_put_contents($project . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT));

    [$code, $out, $err] = $this->in($project, ['composer', 'install', '--no-interaction', '--no-progress']);
    $this->assertSame(0, $code, $out . $err);
    $this->assertFileExists($project . '/vendor/bin/phorward');
    $this->assertFileDoesNotExist($project . '/vendor/deburca/phorward/vendor', 'the package brings no vendor/ of its own');

    $shim = $this->write('shim/ssh', "#!/bin/sh\nwhile [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nshift\nSSH_ORIGINAL_COMMAND=\"\$1\" exec python3 -I \"\$WRAPPER\"\n");
    chmod($shim, 0755);
    mkdir($this->tmp . '/state');
    $this->write('policy.json', json_encode(['results_dir' => $this->tmp . '/results', 'lock_dir' => $this->tmp . '/locks', 'tmp_dir' => $this->tmp]));
    $env = ['PATH' => $this->tmp . '/shim:' . getenv('PATH'), 'PHORWARD_HOST' => 'box', 'WRAPPER' => $root . '/server/phorward-run',
      'PHORWARD_ENGINE' => $root . '/tests/fakes/fake-engine', 'PHORWARD_POLICY' => $this->tmp . '/policy.json', 'FAKE_STATE' => $this->tmp . '/state'];

    [$code, $out, $err] = $this->in($project, ['composer', 'run-script', 'validate:lint', '--no-interaction'], $env);
    $this->assertSame(0, $code, $out . $err);
    $this->assertStringContainsString('verdict: passed', $out . $err);
    $this->assertDoesNotMatchRegularExpression('/^  phpcs\s/m', $out . $err, 'only the lint check was asked for');

    [$code, $out, $err] = $this->in($project, ['composer', 'run-script', 'validate', '--no-interaction'], $env);
    $this->assertSame(0, $code, $out . $err);
    $this->assertMatchesRegularExpression('/^  phpunit\s+passed/m', $out . $err);

    [$code, $out, $err] = $this->in($project, ['composer', 'run-script', 'validate', '--no-interaction'], $env + ['FAKE_BEHAVIOR' => json_encode(['phpcs' => ['rc' => 2]])]);
    $this->assertNotSame(0, $code, 'a failed check fails the script');
    $this->assertStringContainsString('verdict: failed', $out . $err);
  }

}
