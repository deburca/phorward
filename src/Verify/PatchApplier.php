<?php

declare(strict_types=1);

namespace Phorward\Verify;

use Symfony\Component\Process\Process;

/**
 * Applies the patch a server returned for phpcbf. The patch came from a container that ran project code, so it is
 * treated as untrusted input: it is checked line by line before git sees it, and it may change only the files that
 * were asked about, as plain text edits. No new files, deletions, renames, mode changes, symlinks or binary data.
 */
final class PatchApplier {

  /**
   * @param string[] $allowed Paths relative to $root the patch may change.
   * @return string[] The files changed.
   * @throws PatchRejected
   */
  public function apply(string $root, string $patch, array $allowed): array {
    $files = $this->validate($patch, $allowed);
    if ($files === []) {
      return [];
    }
    foreach ($files as $file) {
      if (is_link($root . '/' . $file) || !is_file($root . '/' . $file)) {
        throw new PatchRejected("$file is not a regular file here");
      }
    }
    foreach ([['--check'], []] as $mode) {
      $git = new Process(['git', '-C', $root, 'apply', ...$mode, '-p1', '--whitespace=nowarn', '-'], NULL, NULL, $patch);
      $git->setTimeout(60);
      $git->run();
      if (!$git->isSuccessful()) {
        throw new PatchRejected('git could not apply it: ' . trim($git->getErrorOutput()));
      }
    }
    return $files;
  }

  /**
   * @param string[] $allowed
   * @return string[] The distinct files the patch changes.
   * @throws PatchRejected
   */
  public function validate(string $patch, array $allowed): array {
    if (trim($patch) === '') {
      return [];
    }
    if (str_contains($patch, "\0") || !str_ends_with($patch, "\n")) {
      throw new PatchRejected('it is not plain text ending in a newline');
    }
    $allowedSet = array_flip($allowed);
    $files = [];
    $current = NULL;
    $inHunk = FALSE;
    $remainingOld = 0;
    $remainingNew = 0;
    $lines = explode("\n", substr($patch, 0, -1));
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
      $line = $lines[$i];
      if ($inHunk && ($remainingOld > 0 || $remainingNew > 0)) {
        $first = $line === '' ? ' ' : $line[0];
        if ($first === '\\') {
          continue;
        }
        if ($first === ' ') {
          $remainingOld--;
          $remainingNew--;
        }
        elseif ($first === '-') {
          $remainingOld--;
        }
        elseif ($first === '+') {
          $remainingNew--;
        }
        else {
          throw new PatchRejected('a hunk has a line that is not context, an addition or a removal');
        }
        if ($remainingOld < 0 || $remainingNew < 0) {
          throw new PatchRejected('a hunk is longer than its header says');
        }
        continue;
      }
      $inHunk = FALSE;
      if (str_starts_with($line, '\\ ')) {
        continue;
      }
      if (str_starts_with($line, '--- ')) {
        $next = $lines[$i + 1] ?? '';
        if (!str_starts_with($next, '+++ ')) {
          throw new PatchRejected('a "---" line is not followed by a "+++" line');
        }
        $old = $this->headerPath(substr($line, 4), 'a/');
        $new = $this->headerPath(substr($next, 4), 'b/');
        if ($old !== $new || !isset($allowedSet[$old])) {
          throw new PatchRejected("it changes a file that was not asked about ($old)");
        }
        $files[$old] = TRUE;
        $current = $old;
        $i++;
        continue;
      }
      if ($current !== NULL && preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $m)) {
        $remainingOld = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 1;
        $remainingNew = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
        $inHunk = TRUE;
        continue;
      }
      throw new PatchRejected('it contains something other than plain edits to the requested files');
    }
    if ($inHunk && ($remainingOld > 0 || $remainingNew > 0)) {
      throw new PatchRejected('the last hunk is cut short');
    }
    return array_keys($files);
  }

  private function headerPath(string $text, string $prefix): string {
    // `diff -u --label` puts the label alone; a timestamp after a tab would mean a different tool wrote it.
    if (str_contains($text, "\t") || !str_starts_with($text, $prefix)) {
      throw new PatchRejected('a file header is not in the expected a/<path> and b/<path> form');
    }
    $path = substr($text, strlen($prefix));
    if ($path === '' || str_starts_with($path, '/') || array_intersect(['..', '.', ''], explode('/', $path)) !== []) {
      throw new PatchRejected("an unsafe path in a file header ($path)");
    }
    return $path;
  }

}
