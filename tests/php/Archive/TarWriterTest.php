<?php

declare(strict_types=1);

namespace Phorward\Tests\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Archive\TarWriter;
use Phorward\Tests\TempDirTrait;

final class TarWriterTest extends TestCase {

  use TempDirTrait;

  public function testTheSystemTarReadsIt(): void {
    $tar = $this->write('one.tar', TarWriter::singleFile('doctor.php', "<?php\necho 1;\n"));
    exec('tar -tvf ' . escapeshellarg($tar) . ' 2>&1', $listing, $code);
    $this->assertSame(0, $code, implode("\n", $listing));
    $this->assertCount(1, $listing);
    $this->assertStringContainsString('doctor.php', $listing[0]);
    $this->assertStringContainsString('-rw-r--r--', $listing[0]);
    $this->assertSame("<?php\necho 1;\n", shell_exec('tar -xOf ' . escapeshellarg($tar)));
  }

  public function testSizesAroundTheBlockBoundary(): void {
    foreach ([0, 1, 511, 512, 513, 1500] as $size) {
      $content = str_repeat('x', $size);
      $tar = $this->write("s$size.tar", TarWriter::singleFile('f.txt', $content));
      $this->assertSame($content, (string) shell_exec('tar -xOf ' . escapeshellarg($tar)), "size $size");
      $this->assertSame(0, strlen(TarWriter::singleFile('f.txt', $content)) % 512);
    }
  }

  #[DataProvider('badNames')]
  public function testUnsafeNamesAreRefused(string $name): void {
    $this->expectException(\InvalidArgumentException::class);
    TarWriter::singleFile($name, 'x');
  }

  public static function badNames(): array {
    return [[''], ['/etc/passwd'], ['../x'], ['a/../b'], [str_repeat('a', 101)], ["a\0b"]];
  }

}
