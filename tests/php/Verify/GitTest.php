<?php

declare(strict_types=1);

namespace Phorward\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Phorward\Config\ConfigException;
use Phorward\Tests\GitRepoTrait;
use Phorward\Tests\TempDirTrait;
use Phorward\Verify\Git;
use Phorward\Verify\PathFilter;

final class GitTest extends TestCase {

  use TempDirTrait;
  use GitRepoTrait;

  protected function setUp(): void {
    $this->initRepo('p');
    $this->write('p/src/Old.php', "<?php\nold();\n");
    $this->write('p/src/Gone.php', "<?php\n");
    $this->write('p/README.md', "readme\n");
    $this->commitAll('p', 'first');
    $this->git('p', 'checkout', '-q', '-b', 'work');
  }

  public function testTheBaseIsTheMergeBaseWithTheDefaultBranch(): void {
    $this->write('p/src/New.php', "<?php\n");
    $this->commitAll('p', 'work');
    $this->git('p', 'checkout', '-q', 'main');
    $this->write('p/other.txt', "x\n");
    $this->commitAll('p', 'main moved');
    $this->git('p', 'checkout', '-q', 'work');
    [$ref, $base] = (new Git($this->tmp . '/p'))->mergeBase(NULL);
    $this->assertSame('main', $ref);
    $this->assertSame(trim($this->git('p', 'merge-base', 'main', 'work')), $base);
    $this->assertSame(['src/New.php'], (new Git($this->tmp . '/p'))->changedFiles($base));
  }

  public function testChangedFilesAreAddedModifiedStagedAndUntrackedButNotDeletedOrIgnored(): void {
    $this->write('p/.gitignore', "ignored.php\n");
    $this->commitAll('p', 'ignore');
    $this->write('p/src/Old.php', "<?php\nnew();\n");
    $this->write('p/staged.php', "<?php\n");
    $this->git('p', 'add', 'staged.php');
    $this->write('p/fresh.php', "<?php\n");
    $this->write('p/ignored.php', "<?php\n");
    unlink($this->tmp . '/p/src/Gone.php');
    [, $base] = (new Git($this->tmp . '/p'))->mergeBase(NULL);
    $this->assertSame(['.gitignore', 'fresh.php', 'src/Old.php', 'staged.php'], (new Git($this->tmp . '/p'))->changedFiles($base));
  }

  public function testNothingChangedGivesNoFiles(): void {
    [, $base] = (new Git($this->tmp . '/p'))->mergeBase(NULL);
    $this->assertSame([], (new Git($this->tmp . '/p'))->changedFiles($base));
  }

  public function testAddedLinesCarryTheirLineNumbersAndUntrackedFilesCountWhole(): void {
    $this->write('p/src/Old.php', "<?php\nold();\nadded1();\nadded2();\n");
    $this->write('p/src/Fresh.php', "<?php\nfresh();\n");
    $this->write('p/vendor/x.php', "<?php\nvendor();\n");
    [, $base] = (new Git($this->tmp . '/p'))->mergeBase(NULL);
    $found = [];
    foreach ((new Git($this->tmp . '/p'))->addedLines($base, new PathFilter()) as $line) {
      $found[] = "{$line->path}:{$line->number}:{$line->text}";
    }
    $this->assertSame(['src/Old.php:3:added1();', 'src/Old.php:4:added2();', 'src/Fresh.php:1:<?php', 'src/Fresh.php:2:fresh();', 'src/Fresh.php:3:'], $found);
  }

  public function testPathsAreRelativeToTheProjectRootInsideALargerRepository(): void {
    $this->write('p/sub/lib/A.php', "<?php\n");
    $this->commitAll('p', 'sub');
    $this->write('p/sub/lib/A.php', "<?php\nmore();\n");
    $this->write('p/sub/lib/B.php', "<?php\n");
    $this->write('p/outside.php', "<?php\n");
    $git = new Git($this->tmp . '/p/sub');
    [, $base] = $git->mergeBase(NULL);
    $this->assertSame(['lib/A.php', 'lib/B.php'], $git->changedFiles($base));
    $this->assertSame('lib/A.php', $git->addedLines($base, new PathFilter())[0]->path);
  }

  public function testABaseThatDoesNotExistIsAConfigurationError(): void {
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('"nope"');
    (new Git($this->tmp . '/p'))->mergeBase('nope');
  }

  public function testADirectoryThatIsNotARepositoryIsAConfigurationError(): void {
    mkdir($this->tmp . '/plain');
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('not a git work tree');
    (new Git($this->tmp . '/plain'))->mergeBase(NULL);
  }

  public function testARepositoryWithoutACommitIsAConfigurationError(): void {
    $this->initRepo('empty');
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('no commit yet');
    (new Git($this->tmp . '/empty'))->mergeBase(NULL);
  }

  public function testTheDefaultBranchFallsBackToMasterWhenThereIsNoMain(): void {
    $this->git('p', 'branch', '-m', 'main', 'master');
    [$ref] = (new Git($this->tmp . '/p'))->mergeBase(NULL);
    $this->assertSame('master', $ref);
  }

}
