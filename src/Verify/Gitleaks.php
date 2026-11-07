<?php

declare(strict_types=1);

namespace Phorward\Verify;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs gitleaks over the commits since the merge base and over what is staged, when it is installed. It reads
 * the repository; it never sends anything anywhere.
 */
final class Gitleaks {

  public function __construct(private readonly ?string $binary = NULL) {}

  /**
   * @return array{0: bool, 1: string}|null Whether it found nothing, and its output; NULL when it is not installed.
   */
  public function scan(string $root, string $mergeBase): ?array {
    $binary = $this->binary ?? (new ExecutableFinder())->find('gitleaks');
    if ($binary === NULL || !is_executable($binary)) {
      return NULL;
    }
    $ok = TRUE;
    $output = '';
    foreach ([['detect', '--log-opts', $mergeBase . '..HEAD'], ['protect', '--staged']] as $args) {
      $process = new Process([$binary, ...$args, '--no-banner', '--redact', '--source', $root]);
      $process->setTimeout(120);
      $process->run();
      if (!$process->isSuccessful()) {
        $ok = FALSE;
        $output .= trim($process->getOutput() . $process->getErrorOutput()) . "\n";
      }
    }
    return [$ok, $output];
  }

}
