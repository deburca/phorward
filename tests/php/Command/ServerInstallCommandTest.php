<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Install\AuthorizedKey;
use Phorward\Ssh\TransportResult;
use Phorward\Tests\Fake\SequenceTransport;
use Phorward\Tests\TempDirTrait;

final class ServerInstallCommandTest extends TestCase {

  use TempDirTrait;

  private ?SequenceTransport $transport = NULL;
  private string $project;
  private string $pub;

  protected function setUp(): void {
    parent::setUp();
    exec('command -v ssh-keygen >/dev/null 2>&1', $unused, $code);
    if ($code !== 0) {
      $this->markTestSkipped('ssh-keygen is not installed.');
    }
    $this->project = $this->tmp . '/project';
    $this->write('project/composer.json', json_encode(['name' => 'acme/my-lib']));
    $this->write('project/composer.lock', '{}');
    $this->write('project/src/A.php', "<?php\n");
    $this->write('project/.env', "SECRET=1\n");
    $this->write('project/.git/HEAD', "ref\n");
    $this->write('project/vendor/autoload.php', "<?php\n");
    exec('ssh-keygen -q -t ed25519 -N "" -C "my client" -f ' . escapeshellarg($this->tmp . '/k'));
    $this->pub = $this->tmp . '/k.pub';
  }

  /**
   * @param TransportResult[] $results
   * @return array{0: int, 1: string, 2: string}
   */
  private function install(array $args, array $results = [], array $env = [], ?string $cwd = NULL): array {
    $this->transport = new SequenceTransport($results);
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env += ['XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $app = new Application(Schema::standard(), new Output($out, $err), fn(string $host, int $timeout) => $this->transport);
    $code = $app->run(array_merge(['server:install'], $args), $env, $cwd ?? $this->project);
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  private function allOk(int $count = 4): array {
    return array_fill(0, $count, SequenceTransport::ok("done\n"));
  }

  private function members(string $tar): array {
    $file = $this->write('payload.tar', $tar);
    exec('tar -tf ' . escapeshellarg($file), $names);
    return array_map(fn($n) => preg_replace('#^\./#', '', $n), $names);
  }

  public function testByDefaultItOnlyPrintsThePlan(): void {
    [$code, $out] = $this->install(['admin@build.example.org', '--public-key', $this->pub, '--from', '203.0.113.0/24']);
    $this->assertSame(0, $code, $out);
    $this->assertSame([], $this->transport->calls, 'nothing is sent to the server');
    $this->assertStringContainsString('Plan for admin@build.example.org (nothing has been changed)', $out);
    foreach (['1. Check the server', '2. Install the server kit at /opt/phorward (release ', '3. Create the policy at /etc/phorward/policy.json',
      '4. Build the php-library image for PHP 8.3 as phorward and smoke-test it', 'sudo -n -u phorward -H /opt/phorward/server/build-image --profile php-library --php 8.3',
      'Nothing was changed. Run again with --apply'] as $expected) {
      $this->assertStringContainsString($expected, $out);
    }
    $this->assertStringContainsString(AuthorizedKey::line($this->pub, '/opt/phorward/server/phorward-run', '203.0.113.0/24'), $out);
    $this->assertStringContainsString("Match User phorward\n", str_replace('  ', '', $out) . "\n");
    $this->assertStringContainsString('ForceCommand /opt/phorward/server/phorward-run', $out);
  }

  public function testCustomPathsAndAPolicyElsewhereAreCarriedIntoTheForcedCommand(): void {
    [$code, $out] = $this->install(['admin', '--prefix', '/srv/phorward', '--user', 'runner', '--policy-file', '/srv/etc/p.json',
      '--results-dir', '/srv/results', '--public-key', $this->pub]);
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('restrict,command="env PHORWARD_POLICY=/srv/etc/p.json /srv/phorward/server/phorward-run"', $out);
    $this->assertStringContainsString('ForceCommand env PHORWARD_POLICY=/srv/etc/p.json /srv/phorward/server/phorward-run', $out);
    $this->assertStringContainsString('Match User runner', $out);
    $this->assertStringContainsString('sudo -n install -d -m 750 -o runner /srv/results', $out);
  }

  public function testWithoutAPublicKeyItSaysHowToGetTheLine(): void {
    [, $out] = $this->install(['admin']);
    $this->assertStringContainsString('Pass --public-key FILE', $out);
    $this->assertStringNotContainsString('restrict,command=', $out);
  }

  public function testApplyRunsThePreflightThenEachStepInOrder(): void {
    [$code, $out] = $this->install(['admin', '--apply'], $this->allOk());
    $this->assertSame(0, $code, $out);
    $this->assertCount(4, $this->transport->calls);
    $scripts = $this->transport->scripts();
    $this->assertStringContainsString('sudo -n true', $scripts[0]);
    $this->assertStringContainsString('mv -T /opt/phorward.tmp /opt/phorward', $scripts[1]);
    $this->assertStringContainsString('policy created', $scripts[2]);
    $this->assertStringContainsString('build-image', $scripts[3]);
    foreach (['[1/4] Check the server', '[2/4] Install the server kit', '[3/4] Create the policy', '[4/4] Build the php-library image', 'Done.'] as $line) {
      $this->assertStringContainsString($line, $out);
    }
    $this->assertSame(['sh -c '], array_unique(array_map(fn($s) => substr($s, 0, 6), $scripts)), 'every command is one sh -c argument');
  }

  public function testThePayloadsAreWhatTheyShouldBe(): void {
    $this->install(['admin', '--apply'], $this->allOk());
    [, $kit] = $this->transport->calls[1];
    $names = $this->members($kit);
    $this->assertContains('server/phorward-run', $names);
    $this->assertContains('profiles/drupal-module/manifest.json', $names);
    $this->assertContains('profiles/php-library/Dockerfile', $names);
    $this->assertNotContains('tests/test_phorward_run.py', $names, 'only the kit is sent');
    $this->assertSame([], array_filter($names, fn($n) => str_contains($n, '__pycache__') || str_ends_with($n, '.pyc')));

    [, $policy] = $this->transport->calls[2];
    $this->assertSame(['allowed_profiles' => ['php-library'], 'results_dir' => '/var/lib/phorward/results'], json_decode($policy, TRUE));

    [, $project] = $this->transport->calls[3];
    $names = $this->members($project);
    $this->assertContains('composer.json', $names);
    $this->assertContains('composer.lock', $names);
    $this->assertContains('src/A.php', $names);
    foreach ($names as $name) {
      $this->assertStringNotContainsString('.env', $name);
      $this->assertStringNotContainsString('.git/', $name);
      $this->assertStringNotContainsString('vendor/', $name);
    }
    $this->assertNull($this->transport->calls[0][1], 'the preflight sends nothing');
  }

  public function testNoSudoRunsEverythingAsTheLogin(): void {
    $this->install(['admin', '--apply', '--no-sudo', '--user', 'me'], $this->allOk());
    foreach ($this->transport->scripts() as $script) {
      $this->assertStringNotContainsString('sudo', $script);
    }
    $this->assertStringNotContainsString('-o me', $this->transport->scripts()[2], 'no ownership change without sudo');
  }

  public function testSkipBuildAndSkipSmoke(): void {
    [$code] = $this->install(['admin', '--apply', '--skip-build'], $this->allOk(3));
    $this->assertSame(0, $code);
    $this->assertCount(3, $this->transport->calls);
    $this->assertStringNotContainsString('rsync', $this->transport->scripts()[0], 'rsync is only needed for the smoke test');

    $this->install(['admin', '--apply', '--skip-smoke'], $this->allOk());
    $this->assertStringNotContainsString('smoke-test', $this->transport->scripts()[3]);
    $this->assertStringContainsString('build-image', $this->transport->scripts()[3]);
  }

  public function testConfigurationReachesTheBuild(): void {
    $this->write('project/phorward.json', json_encode(['php' => '8.5', 'dev_packages' => ['drupal/key', 'drupal/simple_oauth']]));
    $this->install(['admin', '--apply'], $this->allOk());
    $build = $this->transport->scripts()[3];
    $this->assertStringContainsString('--php 8.5', $build);
    $this->assertStringContainsString('--dev-package drupal/key --dev-package drupal/simple_oauth', $build);
    $this->install(['admin', '--apply', '--php', '8.4'], $this->allOk());
    $this->assertStringContainsString('--php 8.4', $this->transport->scripts()[3]);
  }

  public function testExcludesFromTheConfigurationApplyToTheProjectArchive(): void {
    $this->write('project/phorward.json', json_encode(['sync' => ['exclude' => ['src']]]));
    $this->install(['admin', '--apply'], $this->allOk());
    $this->assertNotContains('src/A.php', $this->members($this->transport->calls[3][1]));
  }

  public function testAPreflightFailureChangesNothing(): void {
    [$code, $out] = $this->install(['admin', '--apply'], [new TransportResult(11, '', "sudo needs a password for this login, or sudo is missing\n")]);
    $this->assertSame(70, $code);
    $this->assertCount(1, $this->transport->calls);
    $this->assertStringContainsString('FAILED (exit 11)', $out);
    $this->assertStringContainsString('sudo needs a password', $out);
    $this->assertStringContainsString('Nothing was changed.', $out);
    $this->assertStringNotContainsString('Done.', $out);
  }

  public function testALaterFailureStopsTheRest(): void {
    [$code, $out] = $this->install(['admin', '--apply'], [SequenceTransport::ok(), SequenceTransport::ok("kit installed\n"),
      new TransportResult(1, '', "install: cannot create directory\n")]);
    $this->assertSame(70, $code);
    $this->assertCount(3, $this->transport->calls, 'the build is not attempted');
    $this->assertStringContainsString('Stopped here. Earlier steps are done and safe to repeat', $out);
    $this->assertStringContainsString('cannot create directory', $out);
  }

  public function testATimeoutIsReportedAsOne(): void {
    [$code, $out] = $this->install(['admin', '--apply'], [SequenceTransport::ok(), new TransportResult(NULL, '', '', TRUE)]);
    $this->assertSame(70, $code);
    $this->assertStringContainsString('FAILED (timed out)', $out);
  }

  public function testNoProjectMeansNoBuildUnlessSkipped(): void {
    mkdir($this->tmp . '/empty');
    [$code, , $err] = $this->install(['admin'], [], [], $this->tmp . '/empty');
    $this->assertSame(2, $code);
    $this->assertStringContainsString('--skip-build', $err);
    [$code] = $this->install(['admin', '--skip-build'], [], [], $this->tmp . '/empty');
    $this->assertSame(0, $code);
  }

  public function testAProfileThisClientDoesNotKnowNeedsAPhpVersion(): void {
    $this->write('project/phorward.json', json_encode(['profile' => 'custom-profile']));
    [$code, , $err] = $this->install(['admin']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('does not know the profile "custom-profile"', $err);
    [$code, $out] = $this->install(['admin', '--php', '8.3']);
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('--profile custom-profile --php 8.3', $out);
  }

  public function testBadArgumentsAreRefusedBeforeAnythingIsSent(): void {
    $bad = [
      [],
      ['one', 'two'],
      ['-oProxyCommand=id'],
      ['admin;id'],
      ['admin', '--prefix', 'relative/path'],
      ['admin', '--prefix', '/opt/../etc'],
      ['admin', '--prefix', '/opt/phorward/'],
      ['admin', '--prefix', '/opt/a b'],
      ['admin', '--prefix', '/opt/$(id)'],
      ['admin', '--policy-file', '/etc/x;id'],
      ['admin', '--results-dir', "/var/x\ny"],
      ['admin', '--user', 'Bad User'],
      ['admin', '--user', 'root;id'],
      ['admin', '--public-key', $this->tmp . '/missing.pub'],
      ['admin', '--public-key', $this->tmp . '/k'],
      ['admin', '--from', '1.2.3.4" ,no-restrict', '--public-key', $this->pub],
      ['admin', '--bogus', '1'],
      ['admin', '--prefix'],
    ];
    foreach ($bad as $args) {
      [$code, $out, $err] = $this->install($args);
      $this->assertSame(2, $code, json_encode($args) . ' ' . $out . $err);
      $this->assertSame([], $this->transport->calls, json_encode($args));
    }
  }

  public function testNothingFromTheEnvironmentOrAFileCanReachAScript(): void {
    $this->write('project/phorward.json', json_encode(['profile' => "x'; touch /tmp/pwned; '"]));
    [$code] = $this->install(['admin']);
    $this->assertSame(2, $code, 'the profile is validated by the configuration');
    $this->assertFileDoesNotExist('/tmp/pwned');
  }

  public function testHelp(): void {
    [$code, $out] = $this->install(['--help']);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('server:install', $out);
  }

}
