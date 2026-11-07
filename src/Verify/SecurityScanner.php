<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * Scans the lines a change adds for patterns that are almost never right (failures) or need a stated reason
 * (warnings). A heuristic backstop next to a secret scanner, not a replacement for review. Test files are exempt:
 * they legitimately use raw SQL, disabled access checks and so on. The patterns belong to the profile.
 */
final class SecurityScanner {

  /**
   * @param array<int, array{pattern: string, message: string}> $fail
   * @param array<int, array{pattern: string, message: string}> $warn
   * @param string[] $extensions File extensions (without the dot) to scan.
   */
  public function __construct(
    private readonly array $fail,
    private readonly array $warn,
    private readonly array $extensions,
  ) {}

  /**
   * @param AddedLine[] $lines
   * @return Finding[]
   */
  public function scan(array $lines): array {
    $findings = [];
    foreach ($lines as $added) {
      if (!in_array(pathinfo($added->path, PATHINFO_EXTENSION), $this->extensions, TRUE) || $this->exempt($added->path)) {
        continue;
      }
      // A trailing comment is not code; for PHP, look only at what comes before "//".
      $code = str_ends_with($added->path, '.php') ? explode('//', $added->text)[0] : $added->text;
      foreach ([Finding::FAIL => $this->fail, Finding::WARN => $this->warn] as $level => $patterns) {
        foreach ($patterns as $entry) {
          if (preg_match('~' . str_replace('~', '\~', $entry['pattern']) . '~', $code)) {
            $findings[] = new Finding($level, $added->path, $added->number, $entry['message'], mb_substr(trim($added->text), 0, 120));
          }
        }
      }
    }
    return $findings;
  }

  private function exempt(string $path): bool {
    return in_array('tests', explode('/', $path), TRUE);
  }

}
