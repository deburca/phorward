<?php

declare(strict_types=1);

namespace Phorward\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;
use Phorward\Verify\Gitleaks;

final class GitleaksTest extends TestCase {

  use TempDirTrait;

  private function stub(string $body): string {
    $path = $this->write('gitleaks', "#!/bin/sh\n$body\n");
    chmod($path, 0755);
    return $path;
  }

  public function testItIsSkippedWhenNotInstalled(): void {
    $this->assertNull((new Gitleaks($this->tmp . '/nowhere/gitleaks'))->scan($this->tmp, 'abc'));
  }

  public function testACleanScanPassesBothCommands(): void {
    $log = $this->tmp . '/calls';
    $binary = $this->stub('echo "$@" >> ' . escapeshellarg($log));
    $this->assertSame([TRUE, ''], (new Gitleaks($binary))->scan($this->tmp, 'abc123'));
    $calls = file($log, FILE_IGNORE_NEW_LINES);
    $this->assertSame('detect --log-opts abc123..HEAD --no-banner --redact --source ' . $this->tmp, $calls[0]);
    $this->assertSame('protect --staged --no-banner --redact --source ' . $this->tmp, $calls[1]);
  }

  public function testAFindingFailsTheScanAndCarriesTheOutput(): void {
    $binary = $this->stub('echo "leak found in a.php"; exit 1');
    [$ok, $output] = (new Gitleaks($binary))->scan($this->tmp, 'abc');
    $this->assertFalse($ok);
    $this->assertStringContainsString('leak found in a.php', $output);
  }

}
