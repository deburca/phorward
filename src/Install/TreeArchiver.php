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

  /**
   * @param string[] $extraExcludes Globs from the configuration (sync.exclude).
   * @param string[] $paths Paths inside $root to include; the whole tree by default.
   */
  public function create(string $root, array $extraExcludes = [], bool $includeVendor = FALSE, array $paths = ['.']): string {
    if (!is_dir($root)) {
      throw new ConfigException("Cannot archive $root: it is not a directory.");
    }
    $excludes = array_merge(self::SECRETS, self::DEFAULT_EXCLUDES, $includeVendor ? [] : ['vendor'], ['__pycache__', '*.pyc', '.DS_Store', '._*'], $extraExcludes);
    $tail = [];
    foreach ($excludes as $pattern) {
      $tail[] = '--exclude=' . $pattern;
    }
    array_push($tail, '-C', $root, ...$paths);
    // Extended attributes (macOS adds some to every file) only make the server's tar print warnings, so leave
    // them out; a tar that does not know the option is run again without it.
    foreach ([['tar', '--no-xattrs', '-cf', '-'], ['tar', '-cf', '-']] as $head) {
      // COPYFILE_DISABLE keeps macOS tar from adding AppleDouble "._*" files.
      $process = new Process(array_merge($head, $tail), NULL, ['COPYFILE_DISABLE' => '1']);
      $process->setTimeout(300);
      $process->run();
      if ($process->isSuccessful()) {
        return $process->getOutput();
      }
    }
    throw new ConfigException('tar failed: ' . trim($process->getErrorOutput()));
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
