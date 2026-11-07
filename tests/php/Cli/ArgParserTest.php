<?php

declare(strict_types=1);

namespace Phorward\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Phorward\Cli\ArgParser;
use Phorward\Config\ConfigException;

final class ArgParserTest extends TestCase {

  public function testPositionalArgumentsAndOptions(): void {
    $parsed = ArgParser::parse(['one', '--host', 'box', '--php=8.3', 'two']);
    $this->assertSame(['one', 'two'], $parsed['positional']);
    $this->assertSame(['host' => 'box', 'php' => '8.3'], $parsed['options']);
  }

  public function testBooleanForms(): void {
    $this->assertSame(['dry-run' => TRUE], ArgParser::parse(['--dry-run'])['options']);
    $this->assertSame(['sync-include-vendor' => FALSE], ArgParser::parse(['--no-sync-include-vendor'])['options']);
    $this->assertSame(['sync-include-vendor' => TRUE], ArgParser::parse(['--sync-include-vendor'])['options']);
  }

  public function testValuelessOptionsDoNotSwallowThePositionalAfterThem(): void {
    $parsed = ArgParser::parse(['--help', 'topic'], ['help']);
    $this->assertSame(['help' => TRUE], $parsed['options']);
    $this->assertSame(['topic'], $parsed['positional']);
  }

  public function testAnOptionBeforeAnotherOptionHasNoValue(): void {
    $this->assertSame(['host' => TRUE, 'php' => '8.3'], ArgParser::parse(['--host', '--php', '8.3'])['options']);
  }

  public function testEqualsKeepsLaterEqualsSigns(): void {
    $this->assertSame(['x' => 'a=b'], ArgParser::parse(['--x=a=b'])['options']);
  }

  public function testDoubleDashEndsOptions(): void {
    $parsed = ArgParser::parse(['--host', 'box', '--', '--not-an-option']);
    $this->assertSame(['--not-an-option'], $parsed['positional']);
  }

  public function testShortFlagsAndUnknownShortOptions(): void {
    $this->assertSame(['help' => TRUE], ArgParser::parse(['-h'])['options']);
    $this->expectException(ConfigException::class);
    ArgParser::parse(['-x']);
  }

  public function testMalformedOptions(): void {
    $this->expectException(ConfigException::class);
    ArgParser::parse(['--=value']);
  }

}
