<?php

declare(strict_types=1);

namespace Phorward\Tests;

use Symfony\Component\Process\Process;

/**
 * A real git repository in the scratch directory, for tests of what the gates read from git. Needs TempDirTrait.
 */
trait GitRepoTrait {

  protected function git(string $dir, string ...$args): string {
    $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false',
      '-c', 'init.defaultBranch=main', '-C', $this->tmp . '/' . $dir, ...$args]);
    $process->mustRun();
    return $process->getOutput();
  }

  protected function initRepo(string $dir): void {
    mkdir($this->tmp . '/' . $dir, 0777, TRUE);
    $this->git($dir, 'init', '-q', '-b', 'main');
  }

  protected function commitAll(string $dir, string $message): void {
    $this->git($dir, 'add', '-A');
    $this->git($dir, 'commit', '-q', '-m', $message);
  }

}
