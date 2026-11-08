<?php

declare(strict_types=1);

namespace Phorward\Install;

use Phorward\Config\ConfigException;
use Symfony\Component\Process\Process;

/**
 * Packs a directory into a tar archive with tar itself, leaving out what a run never needs and what must not
 * leave this machine (git history, dependencies, and anything that looks like a secret).
 */
final class TreeArchiver {

  /** Never sent, whatever the configuration says. */
  public const SECRETS = ['.env', '.env.*', '*.pem', '*.key', 'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519'];

  /** Left out unless the configuration asks for them. */
  public const DEFAULT_EXCLUDES = ['.git', 'node_modules'];

  /** The largest tree "phorward run" and "phorward verify" send unless sync.max_size says otherwise. */
  public const DEFAULT_MAX_BYTES = 268435456;

  /**
   * Packs the tree into a tar archive and returns its bytes.
   *
   * In a git work tree, and unless $respectGitignore is off, only the files git tracks and the untracked files it
   * does not ignore are packed (the built-in and configured excludes still apply), so generated trees such as a
   * demo site or a build cache stay home. The archive is built in a temporary file, and a tree over $maxBytes is
   * refused with the names of its largest directories before anything is read into memory.
   *
   * @param string[] $extraExcludes Globs from the configuration (sync.exclude).
   * @param string[] $paths Paths inside $root to include; the whole tree by default.
   */
  public function create(string $root, array $extraExcludes = [], bool $includeVendor = FALSE, array $paths = ['.'],
    bool $respectGitignore = TRUE, int $maxBytes = self::DEFAULT_MAX_BYTES): string {
    if (!is_dir($root)) {
      throw new ConfigException("Cannot archive $root: it is not a directory.");
    }
    $excludes = array_merge(self::SECRETS, self::DEFAULT_EXCLUDES, $includeVendor ? [] : ['vendor'], ['__pycache__', '*.pyc', '.DS_Store', '._*'], $extraExcludes);
    $tail = [];
    foreach ($excludes as $pattern) {
      $tail[] = '--exclude=' . $pattern;
    }
    $files = $respectGitignore && $paths === ['.'] ? $this->gitFiles($root) : NULL;
    if ($files !== NULL) {
      // GNU tar applies -C only to the names that follow it, so it comes before the list.
      array_push($tail, '-C', $root, '--null', '-T', '-');
    }
    else {
      array_push($tail, '-C', $root, ...$paths);
    }
    $target = tempnam(sys_get_temp_dir(), 'phorward-tree-');
    if ($target === FALSE) {
      throw new ConfigException('Cannot create a temporary file for the archive.');
    }
    try {
      // Extended attributes (macOS adds some to every file) only make the server's tar print warnings, so leave
      // them out; a tar that does not know the option is run again without it.
      foreach ([['tar', '--no-xattrs', '-cf', $target], ['tar', '-cf', $target]] as $head) {
        // COPYFILE_DISABLE keeps macOS tar from adding AppleDouble "._*" files.
        $process = new Process(array_merge($head, $tail), NULL, ['COPYFILE_DISABLE' => '1']);
        $process->setTimeout(300);
        if ($files !== NULL) {
          $process->setInput(implode("\0", $files) . "\0");
        }
        $process->run();
        if ($process->isSuccessful()) {
          $size = (int) filesize($target);
          if ($size > $maxBytes) {
            throw new ConfigException(sprintf('The project is %s to send, over the limit of %s (sync.max_size). The largest parts are %s. '
              . 'Add what the checks do not need to sync.exclude in phorward.json%s.', self::format($size), self::format($maxBytes),
              $this->largest($root, $target), $files === NULL ? ', or git-ignore it and keep sync.respect_gitignore on' : ''));
          }
          return (string) file_get_contents($target);
        }
      }
      throw new ConfigException('tar failed: ' . trim($process->getErrorOutput()));
    }
    finally {
      @unlink($target);
    }
  }

  /**
   * "256m" or "2g" as a number of bytes.
   */
  public static function parseSize(string $size): int {
    if (!preg_match('/^([0-9]{1,6})([kmg])$/', strtolower($size), $m)) {
      throw new ConfigException("\"$size\" is not a size such as 256m or 1g.");
    }
    return (int) $m[1] * ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$m[2]];
  }

  private static function format(int $bytes): string {
    return $bytes >= 1048576 ? round($bytes / 1048576) . ' MB' : max(1, (int) round($bytes / 1024)) . ' kB';
  }

  /**
   * The files git would commit if everything not ignored were added, relative to $root, or NULL when $root is not in
   * a git work tree (or git is missing), in which case the caller packs the directory as it is.
   *
   * @return string[]|null
   */
  private function gitFiles(string $root): ?array {
    $inside = new Process(['git', '-C', $root, 'rev-parse', '--is-inside-work-tree']);
    $inside->run();
    if (!$inside->isSuccessful() || trim($inside->getOutput()) !== 'true') {
      return NULL;
    }
    $list = new Process(['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard']);
    $list->setTimeout(60);
    $list->run();
    if (!$list->isSuccessful()) {
      return NULL;
    }
    $files = [];
    foreach (explode("\0", $list->getOutput()) as $name) {
      // A tracked file that was deleted from the work tree is still listed; tar would stop on it.
      if ($name !== '' && file_exists($root . '/' . $name)) {
        $files[$name] = $name;
      }
    }
    return $files === [] ? NULL : array_values($files);
  }

  /**
   * The directories that make up most of an archive that is too big, as "demo/web (159 MB), hardware/oneof9 (25 MB)".
   */
  private function largest(string $root, string $tarFile): string {
    $list = new Process(['tar', '-tf', $tarFile]);
    $list->setTimeout(120);
    $list->run();
    $sizes = [];
    foreach (explode("\n", $list->getOutput()) as $name) {
      $name = preg_replace('#^\./#', '', $name);
      $path = $root . '/' . $name;
      if ($name === '' || !is_file($path)) {
        continue;
      }
      $parts = explode('/', $name);
      $key = count($parts) > 2 ? $parts[0] . '/' . $parts[1] : (count($parts) > 1 ? $parts[0] : $name);
      $sizes[$key] = ($sizes[$key] ?? 0) + (int) filesize($path);
    }
    arsort($sizes);
    $top = [];
    foreach (array_slice($sizes, 0, 3, TRUE) as $name => $bytes) {
      $top[] = sprintf('%s (%s)', $name, self::format($bytes));
    }
    return $top === [] ? 'not known' : implode(', ', $top);
  }

  /**
   * A hash of the names and contents of the files that would be packed from the given top-level paths, so the
   * same kit always gets the same id whatever the file times are.
   *
   * @param string[] $paths
   */
  public function contentId(string $root, array $paths): string {
    $entries = [];
    foreach ($paths as $path) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$path", \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        $relative = substr((string) $file->getPathname(), strlen($root) + 1);
        if (preg_match('#(^|/)(__pycache__|\.DS_Store|\._[^/]*)(/|$)|\.pyc$#', $relative) || !$file->isFile()) {
          continue;
        }
        $entries[$relative] = hash_file('sha256', (string) $file->getPathname()) . (is_executable((string) $file->getPathname()) ? 'x' : '-');
      }
    }
    ksort($entries);
    $lines = [];
    foreach ($entries as $name => $digest) {
      $lines[] = "$name $digest";
    }
    return substr(hash('sha256', implode("\n", $lines)), 0, 12);
  }

}
