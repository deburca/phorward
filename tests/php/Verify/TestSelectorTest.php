<?php

declare(strict_types=1);

namespace Phorward\Tests\Verify;

use PHPUnit\Framework\TestCase;
use Phorward\Tests\TempDirTrait;
use Phorward\Verify\TestSelector;

final class TestSelectorTest extends TestCase {

  use TempDirTrait;

  private function drupal(): TestSelector {
    return new TestSelector($this->tmp, 'drupal', ['php', 'module', 'install', 'inc']);
  }

  private function drupalFixture(): void {
    $this->write('src/Hive.php', "<?php class Hive {}\n");
    $this->write('src/Queen.php', "<?php class Queen {}\n");
    $this->write('thing.module', "<?php\n");
    $this->write('tests/src/Kernel/HiveMoveTest.php', "<?php // moves\n");
    $this->write('tests/src/Kernel/QueenTest.php', "<?php use Hive; new Hive(); Hive::x(); Hive::y();\n");
    $this->write('tests/src/Unit/HiveUnitTest.php', "<?php\n");
    $this->write('tests/src/Unit/OtherTest.php', "<?php\n");
    $this->write('modules/sub/src/Sub.php', "<?php class Sub {}\n");
    $this->write('modules/sub/tests/src/Unit/SubTest.php', "<?php\n");
    $this->write('modules/sub/tests/src/Kernel/SubKernelTest.php', "<?php\n");
  }

  public function testAChangedTestSelectsItself(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['tests/src/Kernel/QueenTest.php'], 8);
    $this->assertSame(['tests/src/Kernel/QueenTest.php'], $tests);
  }

  public function testATestThatWasDeletedIsNotSelected(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['tests/src/Kernel/GoneTest.php'], 8);
    $this->assertSame([], $tests);
  }

  public function testASourceFileSelectsTestsNamedAfterItFirstThenTestsThatMentionIt(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['src/Hive.php'], 8);
    $this->assertSame(['tests/src/Kernel/HiveMoveTest.php', 'tests/src/Unit/HiveUnitTest.php', 'tests/src/Kernel/QueenTest.php'], $tests);
  }

  public function testAFileWithNoMatchSelectsTheUnitTestsOfItsExtension(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['thing.module'], 8);
    $this->assertSame(['tests/src/Unit/HiveUnitTest.php', 'tests/src/Unit/OtherTest.php'], $tests);
  }

  /** Equal scores fall back to the path, so the order is stable. */
  public function testASubmoduleFileOnlySelectsItsOwnTests(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['modules/sub/src/Sub.php'], 8);
    $this->assertSame(['modules/sub/tests/src/Kernel/SubKernelTest.php', 'modules/sub/tests/src/Unit/SubTest.php'], $tests);
  }

  public function testTheCapKeepsTheBestMatchesAndReportsHowManyMatched(): void {
    $this->drupalFixture();
    [$tests, $matched] = $this->drupal()->select(['src/Hive.php'], 2);
    $this->assertSame(['tests/src/Kernel/HiveMoveTest.php', 'tests/src/Unit/HiveUnitTest.php'], $tests);
    $this->assertSame(3, $matched);
  }

  public function testNonSourceFilesAndDependenciesSelectNothing(): void {
    $this->drupalFixture();
    [$tests] = $this->drupal()->select(['README.md', 'vendor/acme/X.php', 'docs/Y.php', 'config/a.yml'], 8);
    $this->assertSame([], $tests);
  }

  public function testTheFlatLayoutFindsTestsAnywhereUnderTests(): void {
    $this->write('src/Parser.php', "<?php class Parser {}\n");
    $this->write('src/Util.php', "<?php\n");
    $this->write('tests/ParserTest.php', "<?php\n");
    $this->write('tests/Deep/ParserEdgeTest.php', "<?php new Parser();\n");
    $this->write('tests/Unit/PlainTest.php', "<?php\n");
    $selector = new TestSelector($this->tmp, 'flat', ['php']);
    // The edge test also names the class, so it ranks above the plain one.
    [$tests] = $selector->select(['src/Parser.php'], 8);
    $this->assertSame(['tests/Deep/ParserEdgeTest.php', 'tests/ParserTest.php'], $tests);
    [$fallback] = $selector->select(['src/Util.php'], 8);
    $this->assertSame(['tests/Unit/PlainTest.php'], $fallback);
  }

  public function testAPathThatCouldNotBeSentIsLeftOut(): void {
    $this->write('src/Hive.php', "<?php\n");
    $this->write('tests/src/Unit/Hive Odd Test.php', "<?php\n");
    $this->write('tests/src/Unit/HiveTest.php', "<?php\n");
    [$tests] = $this->drupal()->select(['src/Hive.php'], 8);
    $this->assertSame(['tests/src/Unit/HiveTest.php'], $tests);
  }

  public function testWhatCountsAsATestAndAsSource(): void {
    $selector = $this->drupal();
    $this->assertTrue(TestSelector::isTest('tests/src/Unit/FooTest.php'));
    $this->assertFalse(TestSelector::isTest('src/FooTest.php'));
    $this->assertTrue($selector->isSource('thing.module'));
    $this->assertFalse($selector->isSource('tests/src/Unit/FooTest.php'));
    $this->assertFalse($selector->isSource('vendor/a/B.php'));
    $this->assertFalse($selector->isSource('config/install/a.yml'));
  }

}
