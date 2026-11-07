<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * Which changed paths the gates judge. Dependencies, git's own files and the harness itself are never judged:
 * the harness does not verify itself, and a vendor directory is not the model's code.
 */
final class PathFilter {

  public const ALWAYS_IGNORED = ['vendor/', 'node_modules/', '.git/', '.harness/', '.opencode/'];

  /**
   * @param string[] $extra Prefixes or globs from gates.ignore.
   */
  public function __construct(private readonly array $extra = []) {}

  public function judged(string $path): bool {
    foreach (self::ALWAYS_IGNORED as $prefix) {
      if (str_starts_with($path, $prefix) || str_contains($path, '/' . $prefix)) {
        return FALSE;
      }
    }
    foreach ($this->extra as $pattern) {
      if (str_ends_with($pattern, '/') ? str_starts_with($path, $pattern) : ($path === $pattern || str_starts_with($path, $pattern . '/') || fnmatch($pattern, $path))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param string[] $paths
   * @return string[]
   */
  public function filter(array $paths): array {
    return array_values(array_filter($paths, fn(string $path) => $this->judged($path)));
  }

}
