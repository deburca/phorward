<?php

declare(strict_types=1);

namespace Phorward\Tests\Install;

use PHPUnit\Framework\TestCase;
use Phorward\Config\ConfigException;
use Phorward\Install\TreeArchiver;
use Phorward\Tests\TempDirTrait;

final class TreeArchiverTest extends TestCase {

  use TempDirTrait;

  /**
   * @return string[] the member names of a tar archive
   */
  private function members(string $tar): array {
    $file = $this->write('listing.tar', $tar);
    exec('tar -tf ' . escapeshellarg($file), $names);
    return array_values(array_filter(array_map(fn($n) => preg_replace('#^\./#', '', $n), $names), fn($n) => $n !== '' && !str_ends_with($n, '/')));
  }

  private function tree(): string {
    foreach (['src/A.php', 'composer.json', '.gitignore', 'docs/readme.md', '.git/config', 'node_modules/x/y.js',
      'vendor/autoload.php', 'sub/vendor/z.php', '.env', '.env.local', '.env.example', 'cert.pem', 'private.key',
      'id_rsa', 'id_ed25519', 'id_generator.php', 'src/__pycache__/a.pyc', '.DS_Store'] as $path) {
      $this->write("project/$path", "content of $path\n");
    }
    return $this->tmp . '/project';
  }

  public function testWhatIsLeftOut(): void {
    $files = $this->members((new TreeArchiver())->create($this->tree()));
    sort($files);
    $this->assertSame(['.gitignore', 'composer.json', 'docs/readme.md', 'id_generator.php', 'src/A.php'], $files);
  }

  public function testAFileNamedLikeASecretIsNeverSentButSimilarNamesAre(): void {
    $files = $this->members((new TreeArchiver())->create($this->tree(), [], TRUE));
    foreach (['.env', '.env.local', '.env.example', 'cert.pem', 'private.key', 'id_rsa', 'id_ed25519'] as $secret) {
      $this->assertNotContains($secret, $files, $secret);
    }
    $this->assertContains('id_generator.php', $files, 'only SSH key file names are treated as secrets');
    $this->assertContains('.gitignore', $files);
  }

  public function testVendorCanBeIncluded(): void {
    $files = $this->members((new TreeArchiver())->create($this->tree(), [], TRUE));
    $this->assertContains('vendor/autoload.php', $files);
    $this->assertContains('sub/vendor/z.php', $files);
    $this->assertNotContains('.git/config', $files, 'git history is never sent');
    $this->assertNotContains('node_modules/x/y.js', $files);
  }

  public function testConfiguredExcludes(): void {
    $files = $this->members((new TreeArchiver())->create($this->tree(), ['docs', '*.json']));
    $this->assertNotContains('docs/readme.md', $files);
    $this->assertNotContains('composer.json', $files);
    $this->assertContains('src/A.php', $files);
  }

  public function testOnlyTheNamedPaths(): void {
    $files = $this->members((new TreeArchiver())->create($this->tree(), [], FALSE, ['src']));
    $this->assertSame(['src/A.php'], $files);
  }

  public function testNotADirectory(): void {
    $this->expectException(ConfigException::class);
    (new TreeArchiver())->create($this->tmp . '/nope');
  }

  public function testContentIdIgnoresTimesAndJunkButNotContentOrModes(): void {
    $this->write('kit/server/tool', "#!/bin/sh\n");
    $this->write('kit/profiles/p/manifest.json', '{}');
    chmod($this->tmp . '/kit/server/tool', 0755);
    $archiver = new TreeArchiver();
    $id = $archiver->contentId($this->tmp . '/kit', ['server', 'profiles']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id);

    touch($this->tmp . '/kit/server/tool', time() - 86400);
    $this->write('kit/server/__pycache__/x.pyc', 'junk');
    $this->write('kit/server/.DS_Store', 'junk');
    $this->assertSame($id, $archiver->contentId($this->tmp . '/kit', ['server', 'profiles']), 'times and junk do not change it');

    chmod($this->tmp . '/kit/server/tool', 0644);
    $this->assertNotSame($id, $archiver->contentId($this->tmp . '/kit', ['server', 'profiles']), 'the executable bit does');
    chmod($this->tmp . '/kit/server/tool', 0755);
    $this->write('kit/server/tool', "#!/bin/sh\necho changed\n");
    chmod($this->tmp . '/kit/server/tool', 0755);
    $this->assertNotSame($id, $archiver->contentId($this->tmp . '/kit', ['server', 'profiles']), 'and so does the content');
  }

}
