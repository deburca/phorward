<?php

declare(strict_types=1);

namespace Phorward\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Config\ResolvedConfig;
use Phorward\Config\Schema;
use Phorward\Tests\TempDirTrait;

final class ConfigLoaderTest extends TestCase {

  use TempDirTrait;

  private function load(array $env = [], array $flags = [], ?string $cwd = NULL): ResolvedConfig {
    // An empty environment, plus an XDG directory inside the scratch directory, so the real user file is never read.
    $env += ['XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    return (new ConfigLoader(Schema::standard()))->load($cwd ?? $this->tmp, $env, $flags);
  }

  private function userFile(array $data): string {
    return $this->writeJson('xdg/phorward/config.json', $data);
  }

  private function assertRefused(callable $load, string ...$fragments): void {
    try {
      $load();
      $this->fail('Expected a ConfigException.');
    }
    catch (ConfigException $e) {
      foreach ($fragments as $fragment) {
        $this->assertStringContainsString($fragment, $e->getMessage());
      }
    }
  }

  public function testDefaultsWhenNothingIsConfigured(): void {
    $config = $this->load();
    $this->assertSame('human', $config->get('format'));
    $this->assertSame(3, $config->get('retries'));
    $this->assertSame(2, $config->get('backoff'));
    $this->assertSame(120, $config->get('wait'));
    $this->assertSame(10, $config->get('connect_timeout'));
    $this->assertFalse($config->get('sync.include_vendor'));
    $this->assertSame([], $config->get('sync.exclude'));
    $this->assertNull($config->get('host'));
    $this->assertSame('default', $config->source('host'));
    $this->assertNull($config->projectRoot);
  }

  public function testPrecedenceOfUserFileEnvironmentAndFlags(): void {
    $this->userFile(['retries' => 5, 'host' => 'from-file']);
    $this->assertSame(5, $this->load()->get('retries'));
    $this->assertStringContainsString('user file', $this->load()->source('retries'));

    $config = $this->load(['PHORWARD_RETRIES' => '7']);
    $this->assertSame(7, $config->get('retries'));
    $this->assertSame('environment PHORWARD_RETRIES', $config->source('retries'));
    $this->assertSame('from-file', $config->get('host'), 'untouched keys keep the lower layer');

    $config = $this->load(['PHORWARD_RETRIES' => '7'], ['retries' => '9']);
    $this->assertSame(9, $config->get('retries'));
    $this->assertSame('flag --retries', $config->source('retries'));
  }

  public function testPrecedenceOfProjectFileEnvironmentAndFlags(): void {
    $this->writeJson('phorward.json', ['php' => '8.3', 'checks' => ['lint', 'phpcs']]);
    $config = $this->load();
    $this->assertSame('8.3', $config->get('php'));
    $this->assertSame(['lint', 'phpcs'], $config->get('checks'));
    $this->assertSame('project file ' . $this->tmp . '/phorward.json', $config->source('php'));

    $config = $this->load(['PHORWARD_PHP' => '8.5', 'PHORWARD_CHECKS' => 'phpunit, phpstan']);
    $this->assertSame('8.5', $config->get('php'));
    $this->assertSame(['phpunit', 'phpstan'], $config->get('checks'));

    $config = $this->load(['PHORWARD_PHP' => '8.5'], ['php' => '8.4']);
    $this->assertSame('8.4', $config->get('php'));
  }

  public function testNestedKeysInAProjectFile(): void {
    $this->writeJson('phorward.json', [
      'sync' => ['exclude' => ['docs/*', 'build'], 'include_vendor' => TRUE],
      'limits' => ['memory' => '4g', 'cpus' => 1.5, 'pids' => 256, 'timeout' => 300],
    ]);
    $config = $this->load();
    $this->assertSame(['docs/*', 'build'], $config->get('sync.exclude'));
    $this->assertTrue($config->get('sync.include_vendor'));
    $this->assertSame('4g', $config->get('limits.memory'));
    $this->assertSame(1.5, $config->get('limits.cpus'));
    $this->assertSame(256, $config->get('limits.pids'));
    $this->assertSame(300, $config->get('limits.timeout'));
  }

  public function testExtraPhorwardInComposerJson(): void {
    $this->writeJson('composer.json', ['name' => 'acme/thing', 'extra' => ['phorward' => ['php' => '8.4', 'database' => 'sqlite']]]);
    $config = $this->load();
    $this->assertSame('8.4', $config->get('php'));
    $this->assertSame('sqlite', $config->get('database'));
    $this->assertStringContainsString('extra.phorward', $config->source('php'));
  }

  public function testBothProjectFilesIsAnError(): void {
    $this->writeJson('phorward.json', ['php' => '8.3']);
    $this->writeJson('composer.json', ['name' => 'a/b', 'extra' => ['phorward' => ['php' => '8.4']]]);
    $this->assertRefused(fn() => $this->load(), 'Both', 'phorward.json', 'extra.phorward');
  }

  public function testComposerJsonWithoutExtraIsFine(): void {
    $this->writeJson('composer.json', ['name' => 'acme/thing']);
    $config = $this->load();
    $this->assertNull($config->projectFile);
    $this->assertSame($this->tmp, $config->projectRoot);
  }

  public function testExtraPhorwardMustBeAnObject(): void {
    $this->writeJson('composer.json', ['name' => 'a/b', 'extra' => ['phorward' => ['not', 'an', 'object']]]);
    $this->assertRefused(fn() => $this->load(), 'must be an object');
  }

  public function testProjectRootIsFoundFromASubdirectory(): void {
    $this->writeJson('phorward.json', ['php' => '8.3']);
    mkdir($this->tmp . '/src/deep', 0777, TRUE);
    $config = $this->load([], [], $this->tmp . '/src/deep');
    $this->assertSame($this->tmp, $config->projectRoot);
    $this->assertSame('8.3', $config->get('php'));
  }

  public function testDetectionOfADrupalModule(): void {
    $this->write('thing.info.yml', "name: Thing\n");
    $this->writeJson('composer.json', ['name' => 'acme/thing']);
    $config = $this->load();
    $this->assertSame('drupal-module', $config->get('profile'));
    $this->assertSame('thing', $config->get('module'));
    $this->assertStringStartsWith('detected (found thing.info.yml', $config->source('profile'));
  }

  public function testDetectionOfAPlainLibrary(): void {
    $this->writeJson('composer.json', ['name' => 'Acme/My-Lib']);
    $config = $this->load();
    $this->assertSame('php-library', $config->get('profile'));
    $this->assertSame('my_lib', $config->get('module'));
  }

  public function testSeveralInfoFilesLeaveTheModuleUnset(): void {
    $this->writeJson('composer.json', ['name' => 'acme/thing']);
    $this->write('a.info.yml', 'x');
    $this->write('b.info.yml', 'x');
    $config = $this->load();
    $this->assertSame('drupal-module', $config->get('profile'));
    $this->assertNull($config->get('module'));
  }

  public function testConfiguredValuesBeatDetection(): void {
    $this->write('thing.info.yml', 'x');
    $this->writeJson('phorward.json', ['profile' => 'php-library', 'module' => 'custom']);
    $config = $this->load();
    $this->assertSame('php-library', $config->get('profile'));
    $this->assertSame('custom', $config->get('module'));
  }

  public function testNothingIsDetectedOutsideAProject(): void {
    $config = $this->load();
    $this->assertNull($config->get('profile'));
    $this->assertNull($config->get('module'));
  }

  public function testAUserSettingInTheProjectFileIsRefused(): void {
    foreach (['host', 'retries', 'format'] as $key) {
      $this->writeJson('phorward.json', [$key => $key === 'retries' ? 1 : 'x']);
      $this->assertRefused(fn() => $this->load(), "\"$key\" is a user setting", 'PHORWARD_' . strtoupper($key), 'project file');
    }
  }

  public function testAHostInExtraPhorwardIsRefusedToo(): void {
    $this->writeJson('composer.json', ['name' => 'a/b', 'extra' => ['phorward' => ['host' => 'evil.example']]]);
    $this->assertRefused(fn() => $this->load(), '"host" is a user setting');
  }

  public function testAProjectSettingInTheUserFileIsRefused(): void {
    $this->userFile(['php' => '8.3']);
    $this->assertRefused(fn() => $this->load(), '"php" is a project setting', 'phorward.json', 'PHORWARD_PHP');
    $this->userFile(['sync' => ['include_vendor' => TRUE]]);
    $this->assertRefused(fn() => $this->load(), '"sync.include_vendor" is a project setting');
  }

  public function testEnvironmentAndFlagsMaySetEitherScope(): void {
    $config = $this->load(['PHORWARD_HOST' => 'box', 'PHORWARD_PHP' => '8.3'], ['database' => 'none', 'format' => 'json']);
    $this->assertSame(['box', '8.3', 'none', 'json'], [$config->get('host'), $config->get('php'), $config->get('database'), $config->get('format')]);
  }

  public function testUnknownKeysNameTheNearestValidOne(): void {
    $this->writeJson('phorward.json', ['chekcs' => ['lint']]);
    $this->assertRefused(fn() => $this->load(), 'unknown setting "chekcs"', 'did you mean "checks"');
    $this->writeJson('phorward.json', ['sync' => ['exlude' => ['x']]]);
    $this->assertRefused(fn() => $this->load(), 'unknown setting "sync.exlude"', 'did you mean "sync.exclude"');
    $this->writeJson('phorward.json', ['totally_different_thing' => 1]);
    $this->assertRefused(fn() => $this->load(), 'unknown setting "totally_different_thing"');
  }

  #[DataProvider('secretNames')]
  public function testSecretLookingKeysAreRefusedWithAnExplanation(string $key): void {
    $this->userFile([$key => 'hunter2']);
    $this->assertRefused(fn() => $this->load(), "\"$key\"", 'No phorward setting holds a secret');
  }

  public static function secretNames(): array {
    return [['password'], ['ssh_password'], ['api_token'], ['secret'], ['key'], ['ssh.key'], ['credentials']];
  }

  #[DataProvider('invalidProjectValues')]
  public function testInvalidProjectValuesAreRefused(array $data, string $fragment): void {
    $this->writeJson('phorward.json', $data);
    $this->assertRefused(fn() => $this->load(), $fragment);
  }

  public static function invalidProjectValues(): array {
    return [
      'php without minor' => [['php' => '8'], 'php is not valid'],
      'php not a string' => [['php' => 8.3], 'php must be a non-empty string'],
      'profile with capitals' => [['profile' => 'Drupal'], 'profile is not valid'],
      'unknown check' => [['checks' => ['lint', 'phpmd']], 'checks must be one of'],
      'checks not a list' => [['checks' => 'lint'], 'checks must be a list'],
      'empty checks' => [['checks' => []], 'must not be empty'],
      'duplicate checks' => [['checks' => ['lint', 'lint']], 'twice'],
      'unknown database' => [['database' => 'oracle'], 'database must be one of'],
      'bad package' => [['dev_packages' => ['not a package']], 'dev_packages is not valid'],
      'bad memory' => [['limits' => ['memory' => 'lots']], 'limits.memory is not valid'],
      'too many cpus' => [['limits' => ['cpus' => 500]], 'limits.cpus must be between'],
      'pids as text' => [['limits' => ['pids' => '256']], 'limits.pids must be a whole number'],
      'zero timeout' => [['limits' => ['timeout' => 0]], 'limits.timeout must be between'],
      'bool as text' => [['sync' => ['include_vendor' => 'yes']], 'must be true or false'],
      'newline in exclude' => [['sync' => ['exclude' => ["a\nb"]]], 'sync.exclude is not valid'],
      'module with capitals' => [['module' => 'MyThing'], 'module is not valid'],
    ];
  }

  #[DataProvider('hosts')]
  public function testHostValidation(string $host, bool $valid): void {
    if ($valid) {
      $this->assertSame($host, $this->load(['PHORWARD_HOST' => $host])->get('host'));
      return;
    }
    $this->assertRefused(fn() => $this->load(['PHORWARD_HOST' => $host]), 'host is not valid');
  }

  public static function hosts(): array {
    return [
      'alias' => ['phorward-box', TRUE],
      'user at host' => ['phorward@build.example.org', TRUE],
      'ip' => ['192.0.2.10', TRUE],
      'leading dash would be an ssh option' => ['-oProxyCommand=id', FALSE],
      'double dash' => ['--help', FALSE],
      'space' => ['box -p 22', FALSE],
      'semicolon' => ['box;id', FALSE],
      'quote' => ['box"', FALSE],
      'slash' => ['box/etc', FALSE],
    ];
  }

  public function testEnvironmentValueParsing(): void {
    $config = $this->load([
      'PHORWARD_SYNC_INCLUDE_VENDOR' => 'true',
      'PHORWARD_SYNC_EXCLUDE' => 'a, b/*,c',
      'PHORWARD_LIMITS_CPUS' => '1.5',
      'PHORWARD_LIMITS_TIMEOUT' => '90',
      'PHORWARD_BACKOFF' => '0.5',
    ]);
    $this->assertTrue($config->get('sync.include_vendor'));
    $this->assertSame(['a', 'b/*', 'c'], $config->get('sync.exclude'));
    $this->assertSame(1.5, $config->get('limits.cpus'));
    $this->assertSame(90, $config->get('limits.timeout'));
    $this->assertSame(0.5, $config->get('backoff'));
  }

  public function testBadEnvironmentValuesNameTheVariable(): void {
    $this->assertRefused(fn() => $this->load(['PHORWARD_RETRIES' => 'many']), 'environment PHORWARD_RETRIES', 'whole number');
    $this->assertRefused(fn() => $this->load(['PHORWARD_RETRIES' => '99']), 'between 0 and 10');
    $this->assertRefused(fn() => $this->load(['PHORWARD_SYNC_INCLUDE_VENDOR' => 'maybe']), 'true or false');
    $this->assertRefused(fn() => $this->load(['PHORWARD_DATABASE' => 'oracle']), 'one of mariadb, sqlite, none');
  }

  public function testEmptyEnvironmentVariablesAreIgnored(): void {
    $this->assertNull($this->load(['PHORWARD_HOST' => ''])->get('host'));
  }

  public function testUnknownPhorwardVariablesWarnButServerSideOnesDoNot(): void {
    $config = $this->load(['PHORWARD_HOTS' => 'x', 'PHORWARD_POLICY' => '/etc/p', 'PHORWARD_ENGINE' => 'podman', 'PHORWARD_MODULE_DIR' => '/x']);
    $this->assertCount(1, $config->warnings);
    $this->assertStringContainsString('PHORWARD_HOTS', $config->warnings[0]);
  }

  public function testFlags(): void {
    $config = $this->load([], ['sync-include-vendor' => TRUE, 'limits-memory' => '2g', 'dev-packages' => 'drupal/key,drupal/simple_oauth']);
    $this->assertTrue($config->get('sync.include_vendor'));
    $this->assertSame('2g', $config->get('limits.memory'));
    $this->assertSame(['drupal/key', 'drupal/simple_oauth'], $config->get('dev_packages'));
    $this->assertSame('flag --limits-memory', $config->source('limits.memory'));
    $this->assertFalse($this->load([], ['sync-include-vendor' => FALSE])->get('sync.include_vendor'));
  }

  public function testFlagProblems(): void {
    $this->assertRefused(fn() => $this->load([], ['nonsense' => 'x']), 'Unknown option --nonsense');
    $this->assertRefused(fn() => $this->load([], ['host' => TRUE]), '--host needs a value');
    $this->assertRefused(fn() => $this->load([], ['host' => '-oProxyCommand=id']), 'host is not valid');
  }

  public function testUserFileLocation(): void {
    $explicit = $this->writeJson('elsewhere/mine.json', ['host' => 'explicit']);
    $this->assertSame('explicit', $this->load(['PHORWARD_CONFIG' => $explicit])->get('host'));
    $this->assertSame($explicit, $this->load(['PHORWARD_CONFIG' => $explicit])->userFile);

    $this->writeJson('home/.config/phorward/config.json', ['host' => 'from-home']);
    $this->assertSame('from-home', $this->load(['XDG_CONFIG_HOME' => ''])->get('host'), 'falls back to ~/.config');

    $this->assertRefused(fn() => $this->load(['PHORWARD_CONFIG' => $this->tmp . '/missing.json']), 'does not exist');
  }

  public function testBrokenFiles(): void {
    $this->write('phorward.json', '{ not json');
    $this->assertRefused(fn() => $this->load(), 'phorward.json is not a JSON object');
    $this->write('phorward.json', '["a", "list"]');
    $this->assertRefused(fn() => $this->load(), 'is not a JSON object');
    $this->write('phorward.json', '{}');
    $this->assertSame([], $this->load()->get('checks') ?? []);
  }

}
