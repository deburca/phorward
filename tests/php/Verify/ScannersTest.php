<?php

declare(strict_types=1);

namespace Phorward\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Phorward\Project\ProfileCatalog;
use Phorward\Verify\AddedLine;
use Phorward\Verify\Finding;
use Phorward\Verify\PathFilter;
use Phorward\Verify\SecurityScanner;
use Phorward\Verify\SuppressionCheck;

final class ScannersTest extends TestCase {

  private function scanner(string $profile): SecurityScanner {
    $spec = ProfileCatalog::bundled()->verifySpec($profile);
    $this->assertNotNull($spec);
    return new SecurityScanner($spec['fail'], $spec['warn'], $spec['scan_extensions']);
  }

  /**
   * @return array<int, string> level and message of each finding
   */
  private function scan(string $profile, string $path, string $text): array {
    return array_map(fn(Finding $f) => $f->level . ': ' . $f->message, $this->scanner($profile)->scan([new AddedLine($path, 1, $text)]));
  }

  public function testTheDrupalProfileFailsOnPatternsThatAreAlmostNeverRight(): void {
    foreach ([
      '$x = eval($code);' => 'eval()',
      '$o = unserialize($data);' => 'unserialize',
      'shell_exec($cmd);' => 'shell execution',
      "  requirements:\n    _access: 'TRUE'" => '_access: TRUE',
      '$db->query("SELECT * FROM t WHERE id = $id");' => 'SQL built',
      '$id = $_GET["id"];' => 'superglobal',
      '$h = md5($v);' => 'weak hash',
    ] as $text => $message) {
      $found = $this->scan('drupal-module', str_contains($text, '_access') ? 'x.routing.yml' : 'src/A.php', $text);
      $this->assertNotEmpty($found, $text);
      $this->assertStringContainsString('fail: ', $found[0]);
      $this->assertStringContainsString($message, $found[0], $text);
    }
  }

  public function testWarningsDoNotFail(): void {
    $found = $this->scan('drupal-module', 'src/A.php', '$q->accessCheck(FALSE);');
    $this->assertCount(1, $found);
    $this->assertStringStartsWith('warn: ', $found[0]);
  }

  public function testCodeThatIsFineProducesNothing(): void {
    $this->assertSame([], $this->scan('drupal-module', 'src/A.php', '$db->query("SELECT 1 WHERE id = :id", [":id" => $id]);'));
  }

  public function testATrailingCommentIsNotCode(): void {
    $this->assertSame([], $this->scan('drupal-module', 'src/A.php', 'foo(); // never call eval( here'));
  }

  public function testTestsAreExempt(): void {
    $this->assertSame([], $this->scan('drupal-module', 'tests/src/Kernel/ATest.php', '$q->query("SELECT $x"); eval($y);'));
    $this->assertSame([], $this->scan('drupal-module', 'modules/sub/tests/src/Unit/ATest.php', 'eval($y);'));
  }

  public function testOnlyTheProfilesExtensionsAreScanned(): void {
    $this->assertSame([], $this->scan('drupal-module', 'README.md', 'eval($x)'));
    $this->assertSame([], $this->scan('php-library', 'config.yml', 'eval($x)'));
  }

  public function testTheLibraryProfileFailsOnEvalAndOnlyWarnsAboutShellExecution(): void {
    $this->assertStringStartsWith('fail: ', $this->scan('php-library', 'src/A.php', 'eval($x);')[0]);
    $this->assertStringStartsWith('warn: ', $this->scan('php-library', 'src/A.php', 'proc_open($x, $d, $p);')[0]);
    $this->assertSame([], $this->scan('php-library', 'src/A.php', 'accessCheck(FALSE);'));
  }

  public function testEveryPatternInTheBundledProfilesCompiles(): void {
    foreach (['drupal-module', 'php-library'] as $profile) {
      $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/profiles/$profile/manifest.json"), TRUE);
      $expected = count($manifest['verify']['security']['fail']) + count($manifest['verify']['security']['warn']);
      $spec = ProfileCatalog::bundled()->verifySpec($profile);
      $this->assertSame($expected, count($spec['fail']) + count($spec['warn']), "$profile has a pattern that does not compile");
    }
  }

  public function testSuppressionsAreFoundInPhpAndInNeonFiles(): void {
    $lines = [
      new AddedLine('src/A.php', 4, '    // @phpstan-ignore-next-line'),
      new AddedLine('src/B.php', 9, '    $x = $y; // @phpstan-ignore argument.type'),
      new AddedLine('phpstan-baseline.neon', 3, "\t\t\tmessage: '#^Call to an undefined method#'"),
      new AddedLine('phpstan.neon.dist', 7, '    - identifier: method.notFound'),
      new AddedLine('phpstan.neon', 8, '    level: 6'),
      new AddedLine('src/C.php', 1, '$phpstan = "ignore";'),
      new AddedLine('README.md', 1, 'phpstan-ignore is a thing'),
    ];
    $found = (new SuppressionCheck())->check($lines);
    $this->assertSame(['src/A.php:4', 'src/B.php:9', 'phpstan-baseline.neon:3', 'phpstan.neon.dist:7'],
      array_map(fn(Finding $f) => "{$f->path}:{$f->line}", $found));
  }

  public function testThePathFilterNeverJudgesDependenciesOrTheHarness(): void {
    $filter = new PathFilter(['generated/', 'docs', '*.min.js']);
    foreach (['vendor/a.php', 'node_modules/x.js', '.git/config', '.harness/a', '.opencode/agent/a.md', 'sub/vendor/a.php',
      'generated/A.php', 'docs/a.php', 'docs', 'js/app.min.js'] as $path) {
      $this->assertFalse($filter->judged($path), $path);
    }
    foreach (['src/A.php', 'vendors/a.php', 'documents/a.php'] as $path) {
      $this->assertTrue($filter->judged($path), $path);
    }
  }

}
