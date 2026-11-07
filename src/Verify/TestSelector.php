<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * Picks the tests worth running for a change, by name. A changed test file selects itself. A changed source file
 * selects the tests of the same extension that name its class, plus tests named after it (Hive.php selects
 * Hive*Test.php). A file with no match selects that extension's Unit tests, so a change still gets some feedback.
 * Results are ranked and capped, so the cap drops the weakest match and not the alphabetical tail.
 *
 * The limit: this matches names, not coverage. It can miss a test of something that is only indirectly affected,
 * and CI remains the full check.
 *
 * Layouts (from the profile): "drupal" has tests/src/{Kernel,Unit} in the project and in each modules/<name>;
 * "flat" has *Test.php anywhere under tests/, with Unit meaning a path segment named Unit.
 */
final class TestSelector {

  private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'demo', 'docs', '.harness'];

  /**
   * @param string[] $sourceExtensions File extensions (without the dot) that count as source.
   */
  public function __construct(
    private readonly string $root,
    private readonly string $layout,
    private readonly array $sourceExtensions,
  ) {}

  public static function isTest(string $path): bool {
    return in_array('tests', explode('/', $path), TRUE) && str_ends_with($path, 'Test.php');
  }

  public function isSource(string $path): bool {
    return !self::isTest($path) && in_array(pathinfo($path, PATHINFO_EXTENSION), $this->sourceExtensions, TRUE)
      && !array_intersect(explode('/', $path), self::SKIP_DIRS);
  }

  /**
   * @param string[] $changed Changed paths relative to the root.
   * @return array{0: string[], 1: int} The tests to run, and how many matched before the cap.
   */
  public function select(array $changed, int $max): array {
    $score = [];
    $cache = [];
    foreach ($changed as $path) {
      if (self::isTest($path)) {
        if (is_file($this->root . '/' . $path)) {
          $score[$path] = ($score[$path] ?? 0) + 10000;
        }
        continue;
      }
      if (!$this->isSource($path)) {
        continue;
      }
      $ext = $this->extensionRoot($path);
      $tests = $cache[$ext] ??= $this->testsOf($ext);
      $stem = explode('.', basename($path))[0];
      $hits = [];
      if (str_ends_with($path, '.php')) {
        $word = '/\b' . preg_quote($stem, '/') . '\b/';
        foreach ($tests as $test => $kind) {
          $points = str_starts_with(basename($test), $stem) ? 1000 : 0;
          $points += (int) preg_match_all($word, (string) file_get_contents($this->root . '/' . $test));
          if ($points > 0) {
            $hits[$test] = $points;
          }
        }
      }
      if ($hits === []) {
        foreach ($tests as $test => $kind) {
          if ($kind === 'Unit') {
            $hits[$test] = 1;
          }
        }
      }
      foreach ($hits as $test => $points) {
        $score[$test] = ($score[$test] ?? 0) + $points;
      }
    }
    uksort($score, fn(string $a, string $b) => [$score[$b], $a] <=> [$score[$a], $b]);
    $ordered = array_keys($score);
    // The server takes only plain paths; a name with anything else in it is left out rather than refused.
    $ordered = array_values(array_filter($ordered, fn(string $p) => (bool) preg_match('#^[A-Za-z0-9_./-]+$#', $p)));
    return [array_slice($ordered, 0, $max), count($ordered)];
  }

  /**
   * "." for the project itself, or modules/<name> for a submodule's file (drupal layout only).
   */
  private function extensionRoot(string $path): string {
    $parts = explode('/', $path);
    return $this->layout === 'drupal' && count($parts) >= 3 && $parts[0] === 'modules' ? 'modules/' . $parts[1] : '.';
  }

  /**
   * @return array<string, string> Test path => kind (Kernel, Unit or Other), in a stable order.
   */
  private function testsOf(string $ext): array {
    $found = [];
    $base = $ext === '.' ? $this->root : $this->root . '/' . $ext;
    $dirs = $this->layout === 'drupal'
      ? ['Kernel' => $base . '/tests/src/Kernel', 'Unit' => $base . '/tests/src/Unit']
      : ['Other' => $base . '/tests'];
    foreach ($dirs as $kind => $dir) {
      if (!is_dir($dir)) {
        continue;
      }
      $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
      foreach ($files as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
          continue;
        }
        $relative = ltrim(substr($file->getPathname(), strlen($this->root)), '/');
        if (array_intersect(explode('/', $relative), self::SKIP_DIRS)) {
          continue;
        }
        $found[$relative] = $this->layout === 'drupal' ? $kind : (in_array('Unit', explode('/', $relative), TRUE) ? 'Unit' : 'Other');
      }
    }
    ksort($found);
    return $found;
  }

}
