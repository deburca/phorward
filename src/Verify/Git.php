<?php

declare(strict_types=1);

namespace Phorward\Verify;

use Phorward\Config\ConfigException;
use Symfony\Component\Process\Process;

/**
 * What the gates need from git, read from the working tree: the base to compare with, the files changed since
 * it, and the lines that were added. Nothing here writes to the repository or runs project code.
 */
final class Git {

  /** A file larger than this is not read for added lines (a new generated file would swamp the scan). */
  private const MAX_FILE_BYTES = 1048576;

  public function __construct(private readonly string $root) {}

  /**
   * The commit the changes are measured from: the merge base of HEAD and the base ref.
   *
   * @return array{0: string, 1: string} The base ref's name and the merge-base commit.
   */
  public function mergeBase(?string $configured): array {
    if (trim($this->git(['rev-parse', '--is-inside-work-tree'], TRUE)) !== 'true') {
      throw new ConfigException('"verify" compares with git history, and this is not a git work tree.');
    }
    if (trim($this->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], TRUE)) === '') {
      throw new ConfigException('The repository has no commit yet, so there is nothing to compare the changes with. Make a first commit.');
    }
    $candidates = $configured !== NULL ? [$configured] : $this->defaultBranches();
    foreach ($candidates as $ref) {
      if (trim($this->git(['rev-parse', '--verify', '--quiet', $ref . '^{commit}'], TRUE)) === '') {
        continue;
      }
      $base = trim($this->git(['merge-base', $ref, 'HEAD'], TRUE));
      if ($base !== '') {
        return [$ref, $base];
      }
    }
    throw new ConfigException($configured !== NULL
      ? "The base \"$configured\" is not a commit that shares history with HEAD. Give --base or gates.base a branch or commit that does."
      : 'Cannot tell which branch to compare with: there is no origin/HEAD, main or master. Give --base or set gates.base.');
  }

  /**
   * Files added, copied or modified since the merge base (staged or not), plus untracked files that git does not ignore.
   * Deleted files are not here: there is nothing left to judge. Paths are relative to the project root.
   *
   * @return string[]
   */
  public function changedFiles(string $mergeBase): array {
    $tracked = $this->names(['diff', '--name-only', '-z', '--no-renames', '--diff-filter=ACM', '--relative', $mergeBase, '--']);
    $untracked = $this->names(['ls-files', '-z', '--others', '--exclude-standard']);
    $all = array_values(array_unique(array_merge($tracked, $untracked)));
    sort($all);
    return array_values(array_filter($all, fn(string $path) => is_file($this->root . '/' . $path)));
  }

  /**
   * Every line the change adds, tracked and untracked, in files the filter allows.
   *
   * @return AddedLine[]
   */
  public function addedLines(string $mergeBase, PathFilter $filter): array {
    $lines = [];
    $path = NULL;
    $number = 0;
    $diff = $this->git(['diff', '-U0', '--no-color', '--no-renames', '--diff-filter=ACM', '--relative', $mergeBase, '--'], FALSE);
    foreach (explode("\n", $diff) as $line) {
      if (str_starts_with($line, '+++ ')) {
        $name = rtrim(substr($line, 4), "\t");
        $path = str_starts_with($name, 'b/') && $filter->judged(substr($name, 2)) ? substr($name, 2) : NULL;
      }
      elseif (str_starts_with($line, '@@')) {
        $number = preg_match('/\+(\d+)/', $line, $m) ? (int) $m[1] - 1 : 0;
      }
      elseif (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
        $number++;
        if ($path !== NULL) {
          $lines[] = new AddedLine($path, $number, substr($line, 1));
        }
      }
    }
    // A new file is not in the diff of tracked files: every line of it is an added line.
    foreach ($this->names(['ls-files', '-z', '--others', '--exclude-standard']) as $name) {
      $file = $this->root . '/' . $name;
      if (!$filter->judged($name) || !is_file($file) || is_link($file) || filesize($file) > self::MAX_FILE_BYTES) {
        continue;
      }
      foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $i => $text) {
        $lines[] = new AddedLine($name, $i + 1, $text);
      }
    }
    return $lines;
  }

  /**
   * @return string[]
   */
  private function defaultBranches(): array {
    $head = trim($this->git(['symbolic-ref', '--quiet', 'refs/remotes/origin/HEAD'], TRUE));
    return array_values(array_filter([$head !== '' ? preg_replace('#^refs/remotes/#', '', $head) : NULL, 'main', 'master']));
  }

  /**
   * @param string[] $args
   * @return string[]
   */
  private function names(array $args): array {
    return array_values(array_filter(explode("\0", $this->git($args, FALSE)), fn(string $name) => $name !== ''));
  }

  /**
   * @param string[] $args
   */
  private function git(array $args, bool $lenient): string {
    $process = new Process(['git', '-c', 'core.quotePath=false', '-C', $this->root, ...$args], NULL, ['GIT_OPTIONAL_LOCKS' => '0']);
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful()) {
      if ($lenient) {
        return '';
      }
      throw new ConfigException('git failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
    }
    return $process->getOutput();
  }

}
