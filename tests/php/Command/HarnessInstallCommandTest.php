<?php

declare(strict_types=1);

namespace Phorward\Tests\Command;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\Schema;
use Phorward\Tests\TempDirTrait;

final class HarnessInstallCommandTest extends TestCase {

  use TempDirTrait;

  private const TEMPLATE_FILES = [
    '.opencode/agent/phorward-dev.md',
    '.opencode/agent/phorward-tests.md',
    '.opencode/command/feature.md',
    '.opencode/command/fix-bug.md',
    '.opencode/command/verify.md',
    '.harness/harness.sh',
    '.harness/CONVENTIONS.md',
    '.harness/command',
  ];

  private string $project;

  protected function setUp(): void {
    parent::setUp();
    $this->project = $this->tmp . '/project';
    $this->write('project/composer.json', json_encode(['name' => 'acme/my-lib']));
    $this->write('project/src/A.php', "<?php\n");
  }

  /**
   * @return array{0: int, 1: string, 2: string}
   */
  private function install(array $args = []): array {
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $env = ['XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'];
    $app = new Application(Schema::standard(), new Output($out, $err));
    $code = $app->run(array_merge(['harness:install'], $args), $env, $this->project);
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
  }

  public function testItInstallsEveryTemplateWithTheProjectsNameFilledIn(): void {
    [$code, $out, $err] = $this->install();

    $this->assertSame(0, $code, $err);
    foreach (self::TEMPLATE_FILES as $file) {
      $this->assertFileExists($this->project . '/' . $file);
      $this->assertStringContainsString('created', $out);
      $this->assertStringNotContainsString('{{', (string) file_get_contents($this->project . '/' . $file), $file);
    }
    $dev = (string) file_get_contents($this->project . '/.opencode/agent/phorward-dev.md');
    $this->assertStringContainsString('developer for my_lib, a PHP library', $dev);
    $this->assertStringContainsString('vendor/bin/phorward verify', $dev);
    $this->assertTrue(is_executable($this->project . '/.harness/harness.sh'));
    $this->assertStringContainsString('Conventions for a coding agent working on my_lib', (string) file_get_contents($this->project . '/.harness/CONVENTIONS.md'));
    $this->assertStringContainsString('still reviews the logic and the tests', $out);
  }

  public function testTheCommandTheAgentsCallComesFromTheSettingAndDefaultsToTheVendorBin(): void {
    $this->install();
    $this->assertStringContainsString('`vendor/bin/phorward verify`', (string) file_get_contents($this->project . '/.opencode/command/verify.md'));
    $this->assertSame("vendor/bin/phorward\n", file_get_contents($this->project . '/.harness/command'));

    $this->install(['--harness-command', 'phorward', '--force']);

    $this->assertStringContainsString('`phorward verify`', (string) file_get_contents($this->project . '/.opencode/command/verify.md'));
    $this->assertStringNotContainsString('vendor/bin', (string) file_get_contents($this->project . '/.opencode/agent/phorward-dev.md'));
    $this->assertSame("phorward\n", file_get_contents($this->project . '/.harness/command'));
  }

  public function testRunningItAgainChangesNothing(): void {
    $this->install();
    [$code, $out] = $this->install();

    $this->assertSame(0, $code);
    $this->assertStringNotContainsString('created', $out);
    $this->assertStringNotContainsString('kept', $out);
    $this->assertSame(8, substr_count($out, 'unchanged'));
  }

  public function testItNeverOverwritesAFileThatWasEdited(): void {
    $this->install();
    $conventions = $this->project . '/.harness/CONVENTIONS.md';
    file_put_contents($conventions, "My own rules.\n");

    [$code, $out] = $this->install();

    $this->assertSame(0, $code);
    $this->assertSame("My own rules.\n", file_get_contents($conventions));
    $this->assertMatchesRegularExpression('/kept \(edited\)\s+\.harness\/CONVENTIONS\.md/', $out);
    $this->assertStringContainsString('--force', $out);
  }

  public function testForceReplacesAnEditedFile(): void {
    $this->install();
    $conventions = $this->project . '/.harness/CONVENTIONS.md';
    file_put_contents($conventions, "My own rules.\n");

    [, $out] = $this->install(['--force']);

    $this->assertStringContainsString('Conventions for a coding agent', (string) file_get_contents($conventions));
    $this->assertMatchesRegularExpression('/updated\s+\.harness\/CONVENTIONS\.md/', $out);
  }

  public function testItUpdatesAFileItWroteWhenTheTemplateHasMovedOn(): void {
    $this->install();
    $record = $this->project . '/.harness/installed.json';
    $old = "An older template.\n";
    $path = $this->project . '/.opencode/command/verify.md';
    file_put_contents($path, $old);
    $recorded = json_decode((string) file_get_contents($record), TRUE);
    $recorded['.opencode/command/verify.md'] = hash('sha256', $old);
    file_put_contents($record, json_encode($recorded));

    [, $out] = $this->install();

    $this->assertMatchesRegularExpression('/updated\s+\.opencode\/command\/verify\.md/', $out);
    $this->assertStringContainsString('phorward verify', (string) file_get_contents($path));
  }

  public function testDryRunWritesNothing(): void {
    [$code, $out] = $this->install(['--dry-run']);

    $this->assertSame(0, $code);
    $this->assertStringContainsString('would create', $out);
    $this->assertDirectoryDoesNotExist($this->project . '/.opencode');
    $this->assertDirectoryDoesNotExist($this->project . '/.harness');
  }

  public function testItRefusesArguments(): void {
    [$code, , $err] = $this->install(['extra']);

    $this->assertSame(2, $code);
    $this->assertStringContainsString('takes no arguments', $err);
  }

  public function testItNeedsToKnowTheProject(): void {
    mkdir($this->tmp . '/elsewhere');
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $app = new Application(Schema::standard(), new Output($out, $err));
    $code = $app->run(['harness:install'], ['XDG_CONFIG_HOME' => $this->tmp . '/xdg', 'HOME' => $this->tmp . '/home'], $this->tmp . '/elsewhere');

    $this->assertSame(2, $code);
  }

  public function testTheTemplatesUseOnlyKnownPlaceholdersAndNoProjectSpecificText(): void {
    $files = array_merge(
      glob(dirname(__DIR__, 3) . '/templates/opencode/*/*.md') ?: [],
      glob(dirname(__DIR__, 3) . '/profiles/*/harness/CONVENTIONS.md') ?: [],
      [dirname(__DIR__, 3) . '/templates/harness/harness.sh'],
    );
    $this->assertGreaterThanOrEqual(8, count($files));
    foreach ($files as $file) {
      $text = (string) file_get_contents($file);
      preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $text, $found);
      foreach ($found[1] as $name) {
        $this->assertContains($name, ['project', 'kind', 'phorward'], "$file uses {{{$name}}}");
      }
      $this->assertDoesNotMatchRegularExpression('/hivelog|ddev/i', $text, $file);
    }
  }

}
