<?php

declare(strict_types=1);

namespace Phorward\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Project\Detector;
use Phorward\Tests\TempDirTrait;

final class DetectorTest extends TestCase {

  use TempDirTrait;

  #[DataProvider('names')]
  public function testShortName(string $package, string $expected): void {
    $this->assertSame($expected, Detector::shortName($package));
  }

  public static function names(): array {
    return [
      ['acme/thing', 'thing'],
      ['Acme/My-Lib', 'my_lib'],
      ['vendor/some.name', 'some_name'],
      ['vendor/9lives', 'p_9lives'],
      ['solo', 'solo'],
    ];
  }

  /**
   * The client and server/build-image must agree on the name, or the wrapper cannot find the image.
   */
  #[DataProvider('names')]
  public function testShortNameAgreesWithBuildImage(string $package, string $expected): void {
    $dir = $this->tmp;
    file_put_contents("$dir/composer.json", json_encode(['name' => $package]));
    $script = dirname(__DIR__, 3) . '/server/build-image';
    $out = [];
    exec(escapeshellarg($script) . ' --profile php-library --module ' . escapeshellarg($dir) . ' --print-name 2>&1', $out, $code);
    if ($code !== 0 && str_contains(implode("\n", $out), 'composer.lock')) {
      // build-image wants a lock file before it names a library project; give it one and ask again.
      file_put_contents("$dir/composer.lock", '{}');
      $out = [];
      exec(escapeshellarg($script) . ' --profile php-library --module ' . escapeshellarg($dir) . ' --print-name 2>&1', $out, $code);
    }
    $this->assertSame(0, $code, implode("\n", $out));
    $this->assertSame($expected, trim(implode("\n", $out)));
  }

  public function testDrupalModule(): void {
    $this->write('thing.info.yml', 'x');
    $found = Detector::detect($this->tmp);
    $this->assertSame(['drupal-module', 'thing'], [$found['profile'], $found['module']]);
  }

  public function testLibraryWithoutAName(): void {
    $this->write('composer.json', '{"require": {}}');
    $found = Detector::detect($this->tmp);
    $this->assertNull($found['profile']);
    $this->assertNull($found['module']);
  }

}
