<?php

declare(strict_types=1);

namespace Phorward\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Install\Quote;

final class QuoteTest extends TestCase {

  #[DataProvider('values')]
  public function testQuoting(string $value, string $expected): void {
    $this->assertSame($expected, Quote::sh($value));
  }

  public static function values(): array {
    return [
      'plain' => ['/opt/phorward', '/opt/phorward'],
      'with equals and colon' => ['A=b:c', 'A=b:c'],
      'empty' => ['', "''"],
      'space' => ['a b', "'a b'"],
      'single quote' => ["it's", "'it'\\''s'"],
      'dollar' => ['$(id)', "'\$(id)'"],
      'semicolon' => ['a;b', "'a;b'"],
      'newline' => ["a\nb", "'a\nb'"],
    ];
  }

  /**
   * Whatever is quoted must come out of a real shell as the very same string, and run nothing.
   */
  #[DataProvider('hostile')]
  public function testARealShellReturnsTheOriginal(string $value): void {
    $out = shell_exec('printf %s ' . Quote::sh($value));
    $this->assertSame($value, $out);
  }

  public static function hostile(): array {
    return [["it's"], ['$(touch /tmp/phorward-quote-test)'], ['`id`'], ['a;b|c&d'], ["line1\nline2"], ['*'], ['"quoted"'], ['\\']];
  }

}
