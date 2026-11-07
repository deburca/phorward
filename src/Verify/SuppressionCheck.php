<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * A model that is told to clear phpstan can do it by silencing the finding instead of fixing the code. This finds
 * the two ways of doing that in a change: an added phpstan-ignore comment, and a new entry in a baseline or neon file.
 */
final class SuppressionCheck {

  /**
   * @param AddedLine[] $lines
   * @return Finding[]
   */
  public function check(array $lines): array {
    $findings = [];
    foreach ($lines as $added) {
      $isPhp = str_ends_with($added->path, '.php');
      $isConfig = str_ends_with($added->path, '.neon') || str_ends_with($added->path, '.neon.dist') || str_contains(basename($added->path), 'baseline');
      if ($isPhp && str_contains($added->text, 'phpstan-ignore')) {
        $findings[] = new Finding(Finding::FAIL, $added->path, $added->number, 'a phpstan-ignore comment was added: fix the finding instead of hiding it', mb_substr(trim($added->text), 0, 120));
      }
      elseif ($isConfig && preg_match('/^\s*-?\s*(message|rawMessage|identifier):/', $added->text)) {
        $findings[] = new Finding(Finding::FAIL, $added->path, $added->number, 'a phpstan ignore or baseline entry was added: fix the finding instead of hiding it', mb_substr(trim($added->text), 0, 120));
      }
    }
    return $findings;
  }

}
