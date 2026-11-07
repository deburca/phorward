<?php

declare(strict_types=1);

namespace Phorward\Tests\Report;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Report\Sanitize;

final class SanitizeTest extends TestCase {

  #[DataProvider('terminalCases')]
  public function testTerminal(string $input, string $expected): void {
    $this->assertSame($expected, Sanitize::terminal($input));
  }

  public static function terminalCases(): array {
    return [
      'plain text' => ["ok\nnext\tcolumn", "ok\nnext\tcolumn"],
      'colour codes' => ["\e[31mred\e[0m", 'red'],
      'clear screen and home' => ["\e[2J\e[Hwiped", 'wiped'],
      'cursor movement' => ["a\e[3Ab\e[10;20Hc", 'abc'],
      'window title, bell terminated' => ["x\e]0;owned\x07y", 'xy'],
      'window title, string terminator' => ["x\e]2;owned\e\\y", 'xy'],
      'hyperlink' => ["\e]8;;http://evil.example\x07click\e]8;;\x07", 'click'],
      'two byte escape' => ["a\ecb", 'ab'],
      'carriage return overwrite' => ["safe\roverwritten", 'safeoverwritten'],
      'windows newlines' => ["a\r\nb", "a\nb"],
      'backspace and bell and null' => ["a\x08b\x07c\x00d", 'abcd'],
      'delete' => ["a\x7fb", 'ab'],
      'valid unicode kept' => ["café ✓ 日本", 'café ✓ 日本'],
    ];
  }

  public function testInvalidUtf8IsRepaired(): void {
    $out = Sanitize::terminal("bad \xff\xfe bytes");
    $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    $this->assertStringContainsString('bad', $out);
  }

  public function testXml(): void {
    $this->assertSame("a\tb\nc", Sanitize::xml("a\tb\nc"));
    $this->assertSame('ab', Sanitize::xml("a\x00\x08\x0b\x0c\x1fb"));
    $this->assertSame('ab', Sanitize::xml("a\u{FFFE}\u{FFFF}b"));
    $this->assertSame('😀', Sanitize::xml('😀'));
    $this->assertTrue(mb_check_encoding(Sanitize::xml("x\xffy"), 'UTF-8'));
  }

}
