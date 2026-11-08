<?php

declare(strict_types=1);

namespace Phorward\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Server\Protocol;

final class ProtocolTest extends TestCase {

  public function testTheSmallestRun(): void {
    $this->assertSame('phorward-run run --protocol 1 --profile drupal-module --module thing',
      Protocol::runCommand('drupal-module', 'thing'));
  }

  public function testEverythingAtOnceInTheOrderTheServerDocumentationShows(): void {
    $this->assertSame(
      'phorward-run run --protocol 1 --profile php-library --php 8.3 --module my_lib --checks lint,phpunit --db none '
      . '--tests tests/ATest.php,tests/Sub/BTest.php --memory 4g --cpus 1.5 --pids 256 --timeout 300',
      Protocol::runCommand('php-library', 'my_lib', ['lint', 'phpunit'], '8.3', 'none', ['tests/ATest.php', 'tests/Sub/BTest.php'],
        ['memory' => '4g', 'cpus' => 1.5, 'pids' => 256, 'timeout' => 300]));
  }

  public function testAbsentLimitsAreLeftOut(): void {
    $this->assertSame('phorward-run run --protocol 1 --profile p --module m --timeout 30',
      Protocol::runCommand('p', 'm', [], NULL, NULL, [], ['memory' => NULL, 'cpus' => NULL, 'pids' => NULL, 'timeout' => 30]));
  }

  public function testWholeNumberCpusAreWrittenWithoutADecimal(): void {
    $this->assertStringContainsString('--cpus 2 ', Protocol::runCommand('p', 'm', [], NULL, NULL, [], ['cpus' => 2.0]) . ' ');
    $this->assertStringContainsString('--cpus 0.5', Protocol::runCommand('p', 'm', [], NULL, NULL, [], ['cpus' => 0.5]));
  }

  #[DataProvider('refused')]
  public function testRefusesAnythingThatCouldBreakTheCommandLine(array $args): void {
    $this->expectException(\InvalidArgumentException::class);
    Protocol::runCommand(...$args);
  }

  public static function refused(): array {
    return [
      'profile with a space' => [['profile' => 'a b', 'module' => 'm']],
      'profile with a semicolon' => [['profile' => 'p;id', 'module' => 'm']],
      'module with capitals' => [['profile' => 'p', 'module' => 'My']],
      'module with a dollar' => [['profile' => 'p', 'module' => 'm$(id)']],
      'php injection' => [['profile' => 'p', 'module' => 'm', 'php' => '8.3; id']],
      'unknown check' => [['profile' => 'p', 'module' => 'm', 'checks' => ['lint', 'phpmd']]],
      'unknown database' => [['profile' => 'p', 'module' => 'm', 'db' => 'oracle']],
      'test path up' => [['profile' => 'p', 'module' => 'm', 'tests' => ['../etc/passwd']]],
      'test path absolute' => [['profile' => 'p', 'module' => 'm', 'tests' => ['/etc/passwd']]],
      'test path double slash' => [['profile' => 'p', 'module' => 'm', 'tests' => ['a//b']]],
      'test path option-like' => [['profile' => 'p', 'module' => 'm', 'tests' => ['-x']]],
      'test path with a space' => [['profile' => 'p', 'module' => 'm', 'tests' => ['a b']]],
      'memory text' => [['profile' => 'p', 'module' => 'm', 'limits' => ['memory' => 'lots']]],
      'cpus with too many decimals' => [['profile' => 'p', 'module' => 'm', 'limits' => ['cpus' => '1.234']]],
      'negative pids' => [['profile' => 'p', 'module' => 'm', 'limits' => ['pids' => -1]]],
    ];
  }

  public function testDecode(): void {
    $this->assertSame(['a' => 1], Protocol::decode('{"a": 1}'));
    foreach (['', 'Welcome', '[1, 2]', '"text"', '5', 'null'] as $bad) {
      $this->assertNull(Protocol::decode($bad), $bad);
    }
  }

}
