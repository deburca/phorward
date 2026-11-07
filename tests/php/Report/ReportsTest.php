<?php

declare(strict_types=1);

namespace Phorward\Tests\Report;

use PHPUnit\Framework\TestCase;
use Phorward\Report\HumanReport;
use Phorward\Report\JsonReport;
use Phorward\Report\JunitReport;
use Phorward\Run\Outcome;
use Phorward\Run\ResultClassifier;
use Phorward\Run\RunResult;
use Phorward\Ssh\TransportResult;

final class ReportsTest extends TestCase {

  private function document(string $verdict, array $checks, array $extra = []): array {
    return $extra + ['protocol' => 1, 'kit_version' => '0.1.0', 'run_id' => 'r1', 'verdict' => $verdict, 'image' => 'phorward:thing-php8.3',
      'tool_versions' => ['php' => '8.3.36', 'phpunit' => '11.5.57'], 'limits' => ['requested' => [], 'applied' => [], 'clamped' => []], 'checks' => $checks];
  }

  private function runOf(array $document, int $attempts = 1, float $waited = 0.0): RunResult {
    return new RunResult((new ResultClassifier())->classify(new TransportResult($document['verdict'] === 'passed' ? 0 : 1, json_encode($document), '')), $attempts, $waited);
  }

  private function failedDocument(string $output = "FOUND 3 ERRORS\n"): array {
    return $this->document('failed', [
      ['name' => 'lint', 'status' => 'passed', 'exit_code' => 0, 'duration_ms' => 210, 'output' => '', 'output_truncated' => FALSE, 'output_bytes' => 0],
      ['name' => 'phpcs', 'status' => 'failed', 'exit_code' => 2, 'duration_ms' => 480, 'output' => $output, 'output_truncated' => TRUE, 'output_bytes' => 9000],
      ['name' => 'phpstan', 'status' => 'skipped'],
    ]);
  }

  private function infra(string $reason = 'ssh could not connect: Connection refused'): RunResult {
    return new RunResult(new Outcome(Outcome::INFRA, NULL, $reason, TRUE, 'Run "phorward doctor".'), 4, 14.0);
  }

  public function testHumanReportOfAFailure(): void {
    $out = (new HumanReport())->render($this->runOf($this->failedDocument()), 'box', 'thing');
    foreach (['phorward: thing (phorward:thing-php8.3) on box', 'lint     passed   0.2 s', 'phpcs    FAILED   0.5 s',
      '--- phpcs output (the last part; the check printed more)', 'FOUND 3 ERRORS',
      'verdict: failed (the checks above found problems in the code)', 'server: protocol 1, kit 0.1.0; php 8.3.36, phpunit 11.5.57'] as $expected) {
      $this->assertStringContainsString($expected, $out);
    }
    $this->assertStringNotContainsString('--- lint output', $out, 'passing checks print no output');
  }

  public function testHumanReportOfAnInfrastructureProblemNeverSaysTheCodeIsWrong(): void {
    $out = (new HumanReport())->render($this->infra(), 'box', 'thing');
    $this->assertStringContainsString('verdict: no verdict: ssh could not connect: Connection refused (an infrastructure problem; this says nothing about the code)', $out);
    $this->assertStringContainsString('Run "phorward doctor".', $out);
    $this->assertStringContainsString('(4 attempts, 14.0 s spent waiting between them)', $out);
    $this->assertStringNotContainsString('problems in the code', $out);
  }

  public function testHumanReportOfALimitedRunShowsWhatWasLowered(): void {
    $doc = $this->document('passed', [['name' => 'lint', 'status' => 'passed', 'duration_ms' => 5]],
      ['limits' => ['requested' => ['memory' => '8g'], 'applied' => ['memory' => '6g'], 'clamped' => ['memory']]]);
    $out = (new HumanReport())->render($this->runOf($doc), 'box', NULL);
    $this->assertStringContainsString('limits lowered by the server policy: memory 8g to 6g', $out);
    $this->assertStringContainsString('verdict: passed', $out);
  }

  public function testHumanReportCannotBeUsedToDriveTheTerminal(): void {
    $doc = $this->failedDocument("\e[2J\e[Hfake prompt\e]0;owned\x07 and \rsafe\n");
    $doc['image'] = "img\e[31m";
    $doc['tool_versions'] = ['php' => "8\e[2J.3", "bad\e" => '1'];
    $out = (new HumanReport())->render($this->runOf($doc), 'box', 'thing');
    $this->assertStringNotContainsString("\e", $out);
    $this->assertStringNotContainsString("\x07", $out);
    $this->assertStringNotContainsString("\r", $out);
    $this->assertStringContainsString('fake prompt', $out);
  }

  public function testLongOutputIsCutFromTheFront(): void {
    $lines = implode("\n", array_map(fn($i) => "line $i", range(1, 200)));
    $out = (new HumanReport())->render($this->runOf($this->failedDocument($lines)), 'box', 'thing');
    $this->assertStringContainsString('[... 140 earlier lines left out]', $out);
    $this->assertStringContainsString('line 200', $out);
    $this->assertStringNotContainsString("line 1\n", $out);
  }

  public function testJsonReportKeepsTheServersDocumentAndAddsTheClients(): void {
    $doc = $this->failedDocument();
    $json = json_decode((new JsonReport())->render($this->runOf($doc, 2, 2.0), 'box'), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($doc as $key => $value) {
      $this->assertSame($value, $json[$key], $key);
    }
    $this->assertEquals(['outcome' => 'failed', 'exit_code' => 1, 'reason' => 'failed', 'hint' => NULL, 'code' => NULL, 'attempts' => 2, 'waited_seconds' => 2.0, 'host' => 'box'], $json['client']);
  }

  public function testJsonReportWhenThereWasNoResult(): void {
    $json = json_decode((new JsonReport())->render($this->infra(), 'box'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['client'], array_keys($json));
    $this->assertSame(['infra', 70, 4], [$json['client']['outcome'], $json['client']['exit_code'], $json['client']['attempts']]);
  }

  public function testJunitMarksFailuresSkipsAndPasses(): void {
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML((new JunitReport())->render($this->runOf($this->failedDocument()))));
    $suite = $xml->getElementsByTagName('testsuite')->item(0);
    $this->assertSame(['3', '1', '0', '1'], [$suite->getAttribute('tests'), $suite->getAttribute('failures'), $suite->getAttribute('errors'), $suite->getAttribute('skipped')]);
    $cases = $xml->getElementsByTagName('testcase');
    $this->assertSame(3, $cases->length);
    $this->assertSame('lint', $cases->item(0)->getAttribute('name'));
    $this->assertSame(0, $cases->item(0)->childNodes->length, 'a pass has no child');
    $failure = $cases->item(1)->getElementsByTagName('failure')->item(0);
    $this->assertSame('phpcs failed', $failure->getAttribute('message'));
    $this->assertSame("FOUND 3 ERRORS\n", $failure->textContent);
    $this->assertSame(1, $cases->item(2)->getElementsByTagName('skipped')->length);
  }

  public function testJunitCallsAnInfrastructureProblemAnErrorNotAFailure(): void {
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML((new JunitReport())->render($this->infra())));
    $suite = $xml->getElementsByTagName('testsuite')->item(0);
    $this->assertSame(['1', '0', '1'], [$suite->getAttribute('tests'), $suite->getAttribute('failures'), $suite->getAttribute('errors')]);
    $this->assertStringContainsString('ssh could not connect', $xml->getElementsByTagName('error')->item(0)->textContent);
  }

  public function testJunitIsValidXmlWhateverTheOutputContains(): void {
    $hostile = "<![CDATA[ ]]> </failure><evil/> & \x00\x08\x1b ]]>";
    $xml = new \DOMDocument();
    $this->assertTrue($xml->loadXML((new JunitReport())->render($this->runOf($this->failedDocument($hostile)))));
    $this->assertSame(0, $xml->getElementsByTagName('evil')->length, 'the output cannot add elements');
    $this->assertStringContainsString('<evil/>', $xml->getElementsByTagName('failure')->item(0)->textContent);
  }

}
