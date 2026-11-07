<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;

/**
 * bin/phorward doctor as a real process, with a stand-in for ssh that hands the request to the real
 * server/phorward-run (the way a forced command would) and a fake container engine behind it. So the
 * client, the transport, the tar it sends, the wrapper and the protocol are all the real ones.
 */
final class DoctorEndToEndTest extends TestCase {

  use TempDirTrait;

  private function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function doctor(array $extraEnv = [], array $args = []): array {
    $project = $this->tmp . '/project';
    if (!is_dir($project)) {
      mkdir($project);
      file_put_contents($project . '/smoke.info.yml', "name: Smoke\n");
      file_put_contents($project . '/composer.json', '{"name": "phorward/smoke"}');
    }
    $shim = $this->write('shim/ssh', <<<'SH'
#!/bin/sh
# Stands in for ssh: finds the command after "--" and the host, and runs phorward-run as a forced command would.
while [ "$#" -gt 0 ] && [ "$1" != "--" ]; do shift; done
shift
shift
SSH_ORIGINAL_COMMAND="$1" exec python3 -I "$WRAPPER"
SH);
    chmod($shim, 0755);
    if (!is_dir($this->tmp . '/state')) {
      mkdir($this->tmp . '/state', 0777, TRUE);
    }
    if (!is_file($this->tmp . '/policy.json')) {
      $this->write('policy.json', json_encode([
        'results_dir' => $this->tmp . '/results', 'lock_dir' => $this->tmp . '/locks', 'tmp_dir' => $this->tmp,
        'allowed_profiles' => ['drupal-module'],
      ]));
    }
    $env = $extraEnv + [
      'PATH' => $this->tmp . '/shim:' . getenv('PATH'),
      'HOME' => $this->tmp . '/home', 'XDG_CONFIG_HOME' => $this->tmp . '/xdg',
      'PHORWARD_HOST' => 'box',
      'WRAPPER' => $this->root() . '/server/phorward-run',
      'PHORWARD_ENGINE' => $this->root() . '/tests/fakes/fake-engine',
      'PHORWARD_POLICY' => $this->tmp . '/policy.json',
      'FAKE_STATE' => $this->tmp . '/state',
    ];
    $process = proc_open(array_merge([PHP_BINARY, $this->root() . '/bin/phorward', 'doctor'], $args),
      [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($process), $out, $err];
  }

  public function testAHealthyServer(): void {
    [$code, $out, $err] = $this->doctor();
    $this->assertSame(0, $code, $out . $err);
    $this->assertStringContainsString('ok    configuration: host box, profile drupal-module, module smoke', $out);
    $this->assertStringContainsString('ok    forced command: phorward-run answered', $out);
    $this->assertStringContainsString('ok    image: phorward:smoke-php8.3', $out);
    $this->assertStringContainsString('ok    end to end: a test run passed', $out);
    $this->assertStringContainsString('tools: php 8.3.36, phpcs 4.0.4, phpstan 2.3.5, phpunit 11.5.57', $out);
    $this->assertStringContainsString('WARN  image freshness: the image does not record what it was built from', $out);
    $this->assertStringContainsString('ok    free disk:', $out);
  }

  public function testTheImageHashIsComparedWithTheProject(): void {
    $this->doctor();
    $current = hash_file('sha256', $this->tmp . '/project/composer.json');
    [$code, $out] = $this->doctor(['FAKE_LOCK' => $current]);
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('ok    image freshness: matches composer.json', $out);
    [, $out] = $this->doctor(['FAKE_LOCK' => str_repeat('a', 64)]);
    $this->assertStringContainsString('WARN  image freshness: composer.json has changed since the image was built', $out);
  }

  public function testTheServerHasNoImageForTheProject(): void {
    $this->doctor();
    file_put_contents($this->tmp . '/project/phorward.json', '{"module": "someone_else"}');
    [$code, $out] = $this->doctor();
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  image: no image for profile drupal-module, module someone_else', $out);
  }

  public function testAProfileThePolicyDoesNotAllow(): void {
    $this->doctor();
    file_put_contents($this->tmp . '/policy.json', json_encode([
      'results_dir' => $this->tmp . '/results', 'lock_dir' => $this->tmp . '/locks', 'tmp_dir' => $this->tmp,
      'allowed_profiles' => ['php-library'],
    ]));
    [$code, $out] = $this->doctor();
    $this->assertSame(1, $code);
    $this->assertStringContainsString('end to end', $out);
    $this->assertStringContainsString('profile_not_allowed', $out);
    $this->assertStringContainsString('allowed_profiles', $out);
  }

  public function testABrokenPolicyFile(): void {
    $this->doctor();
    file_put_contents($this->tmp . '/policy.json', '{not json');
    [$code, $out] = $this->doctor();
    $this->assertSame(1, $code);
    $this->assertStringContainsString('FAIL  forced command: the server refused the request: policy_invalid', $out);
  }

}
