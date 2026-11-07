<?php

declare(strict_types=1);

namespace Phorward\Tests\Run;

use PHPUnit\Framework\TestCase;
use Phorward\Run\Outcome;
use Phorward\Run\ResultClassifier;
use Phorward\Run\Runner;
use Phorward\Ssh\TransportResult;
use Phorward\Tests\Fake\SequenceTransport;

final class RunnerTest extends TestCase {

  /** @var float[] */
  private array $slept = [];
  /** @var string[] */
  private array $logged = [];

  private function doc(string $verdict, ?string $code = NULL): TransportResult {
    $doc = ['protocol' => 1, 'verdict' => $verdict, 'checks' => []];
    if ($code !== NULL) {
      $doc['error'] = ['code' => $code, 'message' => 'm'];
    }
    return new TransportResult($verdict === 'passed' ? 0 : ($verdict === 'failed' ? 1 : 70), json_encode($doc), '');
  }

  private function ssh255(): TransportResult {
    return new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n");
  }

  private function drive(array $replies, int $retries = 3, float $backoff = 2.0, int $wait = 120): array {
    $transport = new SequenceTransport($replies);
    $runner = new Runner($transport, new ResultClassifier(), function (float $s) { $this->slept[] = $s; }, function (string $l) { $this->logged[] = $l; });
    $tarCalls = 0;
    $result = $runner->run('cmd', function () use (&$tarCalls) { $tarCalls++; return 'TAR'; }, $retries, $backoff, $wait, 60.0);
    return [$result, $transport, $tarCalls];
  }

  public function testAPassNeedsOneAttempt(): void {
    [$result, $transport, $tarCalls] = $this->drive([$this->doc('passed')]);
    $this->assertSame([Outcome::PASSED, 1, []], [$result->outcome->kind, $result->attempts, $this->slept]);
    $this->assertSame([['cmd', 'TAR']], $transport->calls);
    $this->assertSame(1, $tarCalls);
  }

  public function testAFailureIsNeverRetried(): void {
    [$result] = $this->drive([$this->doc('failed')]);
    $this->assertSame([Outcome::FAILED, 1], [$result->outcome->kind, $result->attempts]);
    $this->assertSame([], $this->slept);
  }

  public function testTransientFailuresAreRetriedWithADoublingWait(): void {
    [$result, $transport, $tarCalls] = $this->drive([$this->ssh255(), $this->ssh255(), $this->doc('passed')]);
    $this->assertSame([Outcome::PASSED, 3], [$result->outcome->kind, $result->attempts]);
    $this->assertSame([2.0, 4.0], $this->slept);
    $this->assertSame(6.0, $result->waitedSeconds);
    $this->assertCount(2, $this->logged);
    $this->assertSame(1, $tarCalls, 'the tree is archived once, not once per attempt');
    $this->assertSame('TAR', $transport->calls[2][1], 'and the same archive is sent each time');
  }

  public function testItGivesUpAfterTheConfiguredRetries(): void {
    [$result, $transport] = $this->drive([$this->ssh255(), $this->ssh255(), $this->ssh255(), $this->ssh255()], retries: 3);
    $this->assertSame([Outcome::INFRA, 4, 70], [$result->outcome->kind, $result->attempts, $result->outcome->exitCode()]);
    $this->assertSame([2.0, 4.0, 8.0], $this->slept);
    $this->assertCount(4, $transport->calls, 'one attempt plus three retries');
  }

  public function testZeroRetriesMeansOneAttempt(): void {
    [$result] = $this->drive([$this->ssh255()], retries: 0);
    $this->assertSame([Outcome::INFRA, 1], [$result->outcome->kind, $result->attempts]);
    $this->assertSame([], $this->slept);
  }

  public function testTheWaitBetweenRetriesIsCapped(): void {
    $this->drive(array_fill(0, 8, $this->ssh255()), retries: 7, backoff: 10.0);
    $this->assertSame([10.0, 20.0, 30.0, 30.0, 30.0, 30.0, 30.0], $this->slept);
  }

  public function testAConfigurationErrorAndAResourceLimitAreNotRetried(): void {
    foreach (['bad_request', 'no_image', 'timeout', 'oom_killed'] as $code) {
      $this->slept = [];
      [$result] = $this->drive([$this->doc('error', $code)]);
      $this->assertSame(1, $result->attempts, $code);
      $this->assertSame([], $this->slept, $code);
    }
  }

  public function testABusyServerIsRetriedWithinTheWaitBudget(): void {
    [$result] = $this->drive([$this->doc('error', 'busy'), $this->doc('error', 'busy'), $this->doc('passed')]);
    $this->assertSame([Outcome::PASSED, 3], [$result->outcome->kind, $result->attempts]);
    $this->assertSame([2.0, 4.0], $this->slept);
    $this->assertStringContainsString('The server is busy', $this->logged[0]);
  }

  public function testABusyServerThatStaysBusyEndsAs75WhenTheBudgetIsSpent(): void {
    [$result, $transport] = $this->drive(array_fill(0, 20, $this->doc('error', 'busy')), wait: 10);
    $this->assertSame([Outcome::BUSY, 75], [$result->outcome->kind, $result->outcome->exitCode()]);
    $this->assertSame([2.0, 4.0], $this->slept, 'the next wait of 8 s would pass the 10 s budget');
    $this->assertCount(3, $transport->calls);
    $this->assertLessThanOrEqual(10, $result->waitedSeconds);
  }

  public function testNoWaitBudgetMeansNoWaiting(): void {
    [$result] = $this->drive([$this->doc('error', 'busy')], wait: 0);
    $this->assertSame([Outcome::BUSY, 1], [$result->outcome->kind, $result->attempts]);
    $this->assertSame([], $this->slept);
  }

  public function testABackoffOfZeroCannotSpinForever(): void {
    [$result] = $this->drive(array_fill(0, 50, $this->doc('error', 'busy')), backoff: 0.0, wait: 5);
    $this->assertSame(Outcome::BUSY, $result->outcome->kind);
    $this->assertSame([1.0, 1.0, 1.0, 1.0, 1.0], $this->slept, 'a busy retry waits at least a second');
  }

  public function testInfrastructureRetriesAndBusyWaitsAreCountedSeparately(): void {
    [$result] = $this->drive([$this->ssh255(), $this->doc('error', 'busy'), $this->ssh255(), $this->doc('passed')], retries: 2);
    $this->assertSame([Outcome::PASSED, 4], [$result->outcome->kind, $result->attempts]);
  }

}
