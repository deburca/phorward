<?php

declare(strict_types=1);

namespace Phorward\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\GitRepoTrait;
use Phorward\Tests\TempDirTrait;
use Phorward\Verify\PatchApplier;
use Phorward\Verify\PatchRejected;

final class PatchApplierTest extends TestCase {

  use TempDirTrait;
  use GitRepoTrait;

  private const GOOD = "--- a/src/A.php\n+++ b/src/A.php\n@@ -1,3 +1,3 @@\n <?php\n-function a(){}\n+function a() {}\n \n";

  private function project(): string {
    $this->write('p/src/A.php', "<?php\nfunction a(){}\n\n");
    $this->write('p/src/B.php', "<?php\n");
    return $this->tmp . '/p';
  }

  public function testItAppliesAPlainEditToARequestedFile(): void {
    $root = $this->project();
    $files = (new PatchApplier())->apply($root, self::GOOD, ['src/A.php']);

    $this->assertSame(['src/A.php'], $files);
    $this->assertSame("<?php\nfunction a() {}\n\n", file_get_contents($root . '/src/A.php'));
  }

  public function testAnEmptyPatchChangesNothing(): void {
    $root = $this->project();
    $this->assertSame([], (new PatchApplier())->apply($root, '', ['src/A.php']));
    $this->assertSame([], (new PatchApplier())->apply($root, "  \n", ['src/A.php']));
  }

  /**
   * @dataProvider hostile
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('hostile')]
  public function testItRefusesAnythingButPlainEditsToRequestedFiles(string $patch, string $why): void {
    $root = $this->project();
    $before = file_get_contents($root . '/src/A.php');
    try {
      (new PatchApplier())->apply($root, $patch, ['src/A.php']);
      $this->fail("accepted: $why");
    }
    catch (PatchRejected) {
      $this->assertSame($before, file_get_contents($root . '/src/A.php'), 'nothing was changed');
      $this->assertFileDoesNotExist($this->tmp . '/evil');
    }
  }

  /**
   * @return array<string, array{string, string}>
   */
  public static function hostile(): array {
    $hunk = "@@ -1 +1 @@\n-<?php\n+<?php /* x */\n";
    return [
      'another file' => ["--- a/src/B.php\n+++ b/src/B.php\n$hunk", 'file not asked about'],
      'a new file' => ["--- /dev/null\n+++ b/src/New.php\n@@ -0,0 +1 @@\n+<?php\n", 'creates a file'],
      'a deletion' => ["--- a/src/A.php\n+++ /dev/null\n@@ -1,3 +0,0 @@\n-<?php\n-function a(){}\n-\n", 'deletes a file'],
      'a different new name' => ["--- a/src/A.php\n+++ b/src/C.php\n$hunk", 'renames'],
      'a git header' => ["diff --git a/src/A.php b/src/A.php\nnew mode 100755\n--- a/src/A.php\n+++ b/src/A.php\n$hunk", 'mode change'],
      'a rename header' => ["rename from src/A.php\nrename to src/Z.php\n", 'rename'],
      'a parent directory' => ["--- a/../evil\n+++ b/../evil\n$hunk", 'path escape'],
      'an absolute path' => ["--- a//etc/passwd\n+++ b//etc/passwd\n$hunk", 'absolute path'],
      'a timestamp header' => ["--- a/src/A.php\t2026-01-01\n+++ b/src/A.php\t2026-01-01\n$hunk", 'foreign tool'],
      'binary data' => ["Binary files a/src/A.php and b/src/A.php differ\n", 'binary'],
      'text before the header' => ["hello\n--- a/src/A.php\n+++ b/src/A.php\n$hunk", 'noise'],
      'a hunk longer than declared' => ["--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1 @@\n-<?php\n+<?php\n+extra\n", 'hunk overrun'],
      'a hunk cut short' => ["--- a/src/A.php\n+++ b/src/A.php\n@@ -1,3 +1,3 @@\n <?php\n", 'short hunk'],
      'a context that does not match' => ["--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1 @@\n-<?xml\n+<?php\n", 'git refuses'],
      'no final newline' => ["--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1 @@\n-<?php\n+<?php /* x */", 'cut'],
      'a header without its pair' => ["--- a/src/A.php\n@@ -1 +1 @@\n-<?php\n+<?php\n", 'half header'],
    ];
  }

  public function testItRefusesAFileThatIsASymlinkHere(): void {
    $root = $this->project();
    $this->write('target.php', "<?php\nfunction a(){}\n\n");
    unlink($root . '/src/A.php');
    symlink($this->tmp . '/target.php', $root . '/src/A.php');

    $this->expectException(PatchRejected::class);
    (new PatchApplier())->apply($root, self::GOOD, ['src/A.php']);
  }

  public function testItMakesNoChangeWhenALaterFileFails(): void {
    $root = $this->project();
    $two = self::GOOD . "--- a/src/B.php\n+++ b/src/B.php\n@@ -1 +1 @@\n-<?xml\n+<?php\n";
    try {
      (new PatchApplier())->apply($root, $two, ['src/A.php', 'src/B.php']);
      $this->fail('accepted');
    }
    catch (PatchRejected) {
      $this->assertSame("<?php\nfunction a(){}\n\n", file_get_contents($root . '/src/A.php'), 'the check ran before anything was written');
    }
  }

}
