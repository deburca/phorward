<?php

declare(strict_types=1);

namespace Phorward\Tests\Install;

use PHPUnit\Framework\TestCase;
use Phorward\Config\ConfigException;
use Phorward\Install\TreeArchiver;
use Phorward\Tests\GitRepoTrait;
use Phorward\Tests\TempDirTrait;

final class TreeArchiverTest extends TestCase {

  use TempDirTrait;
  use GitRepoTrait;

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


  private function gitProject(): string {
    $this->initRepo('repo');
    $this->write('repo/.gitignore', "/build/\n*.log\n");
    foreach (['src/A.php', 'src/Gone.php', 'composer.json'] as $path) {
      $this->write("repo/$path", "tracked $path\n");
    }
    $this->commitAll('repo', 'first');
    $this->write('repo/src/New.php', "untracked\n");
    $this->write('repo/build/big.bin', "generated\n");
    $this->write('repo/debug.log', "ignored\n");
    $this->write('repo/.env', "SECRET=1\n");
    unlink($this->tmp . '/repo/src/Gone.php');
    return $this->tmp . '/repo';
  }

  public function testInAGitTreeOnlyTrackedAndUnignoredFilesAreSent(): void {
    $files = $this->members((new TreeArchiver())->create($this->gitProject()));
    sort($files);
    $this->assertSame(['.gitignore', 'composer.json', 'src/A.php', 'src/New.php'], $files);
  }

  public function testGitIgnoreCanBeSwitchedOff(): void {
    $files = $this->members((new TreeArchiver())->create($this->gitProject(), [], FALSE, ['.'], FALSE));
    $this->assertContains('build/big.bin', $files);
    $this->assertContains('debug.log', $files);
    $this->assertNotContains('.env', $files, 'secrets are never sent');
  }

  public function testExcludesStillApplyInAGitTree(): void {
    $files = $this->members((new TreeArchiver())->create($this->gitProject(), ['src/New.php']));
    $this->assertNotContains('src/New.php', $files);
    $this->assertContains('src/A.php', $files);
  }

  public function testANestedRepositoryDoesNotBreakTheArchive(): void {
    $project = $this->gitProject();
    $this->initRepo('repo/nested');
    $this->write('repo/nested/inner.php', "inner\n");
    $this->commitAll('repo/nested', 'inner');

    $files = $this->members((new TreeArchiver())->create($project));

    $this->assertContains('nested/inner.php', $files);
    $this->assertNotContains('nested/.git/HEAD', $files);
  }

  public function testAFolderInsideALargerRepositoryGetsOnlyItsOwnFiles(): void {
    $this->gitProject();
    $this->write('repo/module/m.php', "m\n");
    $this->commitAll('repo', 'module');

    $files = $this->members((new TreeArchiver())->create($this->tmp . '/repo/module'));

    $this->assertSame(['m.php'], $files);
  }

  public function testATreeOverTheLimitIsRefusedWithTheLargestDirectoriesNamed(): void {
    $this->write('project/demo/web/core/big.bin', str_repeat('x', 300000));
    $this->write('project/src/small.php', "<?php\n");
    try {
      (new TreeArchiver())->create($this->tmp . '/project', [], FALSE, ['.'], TRUE, 100000);
      $this->fail('expected a refusal');
    }
    catch (ConfigException $e) {
      $this->assertStringContainsString('sync.max_size', $e->getMessage());
      $this->assertStringContainsString('demo/web', $e->getMessage());
      $this->assertStringContainsString('sync.exclude', $e->getMessage());
      $this->assertStringNotContainsString('small.php', $e->getMessage());
    }
  }

  public function testATreeUnderTheLimitIsSent(): void {
    $this->write('project/src/small.php', "<?php\n");
    $files = $this->members((new TreeArchiver())->create($this->tmp . '/project', [], FALSE, ['.'], TRUE, 100000));
    $this->assertSame(['src/small.php'], $files);
  }

  public function testSizesAreParsed(): void {
    $this->assertSame(256 * 1048576, TreeArchiver::parseSize('256m'));
    $this->assertSame(1073741824, TreeArchiver::parseSize('1G'));
    $this->expectException(ConfigException::class);
    TreeArchiver::parseSize('lots');
  }

  public function testNoTemporaryFileIsLeftBehind(): void {
    $before = glob(sys_get_temp_dir() . '/phorward-tree-*') ?: [];
    $this->write('project/a.php', "<?php\n");
    (new TreeArchiver())->create($this->tmp . '/project');
    try {
      (new TreeArchiver())->create($this->tmp . '/project', [], FALSE, ['.'], TRUE, 1);
    }
    catch (ConfigException) {
    }
    $this->assertSame($before, glob(sys_get_temp_dir() . '/phorward-tree-*') ?: []);
  }

}
