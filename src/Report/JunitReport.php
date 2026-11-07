<?php

declare(strict_types=1);

namespace Phorward\Report;

use Phorward\Run\Outcome;
use Phorward\Run\RunResult;

/**
 * JUnit XML, for CI systems that read test results: one test suite, one test case per check. A failed check is a
 * <failure>; a check that could not give a verdict (or a run that never happened) is an <error>, so a dashboard
 * can tell "the code is wrong" from "the machinery is wrong".
 */
final class JunitReport {

  public function render(RunResult $run): string {
    $outcome = $run->outcome;
    $cases = [];
    $result = $outcome->result;
    $time = 0.0;
    if ($result !== NULL && is_array($result['checks'] ?? NULL)) {
      foreach ($result['checks'] as $check) {
        if (!is_array($check)) {
          continue;
        }
        $seconds = isset($check['duration_ms']) ? (float) $check['duration_ms'] / 1000 : 0.0;
        $time += $seconds;
        $cases[] = $this->testCase((string) ($check['name'] ?? 'unknown'), (string) ($check['status'] ?? 'error'), $seconds, $check);
      }
    }
    if ($cases === []) {
      $cases[] = $this->testCase('run', 'error', 0.0, ['output' => $outcome->reason . ($outcome->hint ? "\n" . $outcome->hint : '')]);
    }
    $failures = count(array_filter($cases, fn(array $c) => $c['kind'] === 'failure'));
    $errors = count(array_filter($cases, fn(array $c) => $c['kind'] === 'error'));
    $skipped = count(array_filter($cases, fn(array $c) => $c['kind'] === 'skipped'));

    $xml = new \DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = TRUE;
    $suites = $xml->appendChild($xml->createElement('testsuites'));
    $suite = $suites->appendChild($xml->createElement('testsuite'));
    foreach (['name' => 'phorward', 'tests' => (string) count($cases), 'failures' => (string) $failures, 'errors' => (string) $errors,
      'skipped' => (string) $skipped, 'time' => sprintf('%.3f', $time)] as $name => $value) {
      $suite->setAttribute($name, $value);
    }
    foreach ($cases as $case) {
      $element = $suite->appendChild($xml->createElement('testcase'));
      $element->setAttribute('classname', 'phorward');
      $element->setAttribute('name', Sanitize::xml($case['name']));
      $element->setAttribute('time', sprintf('%.3f', $case['time']));
      if ($case['kind'] !== NULL) {
        $child = $element->appendChild($xml->createElement($case['kind']));
        $child->setAttribute('message', Sanitize::xml($case['message']));
        if ($case['kind'] !== 'skipped') {
          $child->appendChild($xml->createTextNode(Sanitize::xml($case['text'])));
        }
      }
    }
    return $xml->saveXML();
  }

  /**
   * @return array{name: string, time: float, kind: ?string, message: string, text: string}
   */
  private function testCase(string $name, string $status, float $time, array $check): array {
    $text = (string) ($check['output'] ?? '');
    $reason = (string) ($check['reason'] ?? '');
    return match ($status) {
      'passed' => ['name' => $name, 'time' => $time, 'kind' => NULL, 'message' => '', 'text' => ''],
      'failed' => ['name' => $name, 'time' => $time, 'kind' => 'failure', 'message' => "$name failed", 'text' => $text],
      'skipped' => ['name' => $name, 'time' => $time, 'kind' => 'skipped', 'message' => $reason !== '' ? $reason : 'skipped', 'text' => ''],
      default => ['name' => $name, 'time' => $time, 'kind' => 'error', 'message' => $reason !== '' ? $reason : "$name could not be run", 'text' => $text],
    };
  }

}
