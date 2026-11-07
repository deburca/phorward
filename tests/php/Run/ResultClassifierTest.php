<?php

declare(strict_types=1);

namespace Phorward\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phorward\Run\Outcome;
use Phorward\Run\ResultClassifier;
use Phorward\Ssh\TransportResult;

final class ResultClassifierTest extends TestCase {

  private function body(string $verdict, ?string $code = NULL, array $extra = []): string {
    $doc = ['protocol' => 1, 'verdict' => $verdict, 'checks' => [['name' => 'lint', 'status' => 'passed']]] + $extra;
    if ($code !== NULL) {
      $doc['error'] = ['code' => $code, 'message' => "message for $code"];
    }
    return json_encode($doc);
  }

  /**
   * @return array{0: string, 1: int, 2: bool}
   */
  private function classify(TransportResult $reply): array {
    $o = (new ResultClassifier())->classify($reply);
    return [$o->kind, $o->exitCode(), $o->retryable];
  }

  public function testThePassAndTheFailureAreWhatTheServerSaid(): void {
    $this->assertSame([Outcome::PASSED, 0, FALSE], $this->classify(new TransportResult(0, $this->body('passed'), '')));
    $this->assertSame([Outcome::FAILED, 1, FALSE], $this->classify(new TransportResult(1, $this->body('failed'), '')));
  }

  #[DataProvider('errorCodes')]
  public function testServerErrorCodes(string $code, string $kind, int $exit, bool $retryable): void {
    $this->assertSame([$kind, $exit, $retryable], $this->classify(new TransportResult($exit === 1 ? 70 : $exit, $this->body('error', $code), '')), $code);
  }

  public static function errorCodes(): array {
    return [
      'busy' => ['busy', Outcome::BUSY, 75, TRUE],
      'a request the server refused' => ['bad_request', Outcome::CONFIG, 2, FALSE],
      'a tree the server refused' => ['bad_archive', Outcome::CONFIG, 2, FALSE],
      'protocol mismatch' => ['protocol_mismatch', Outcome::CONFIG, 2, FALSE],
      'profile not allowed' => ['profile_not_allowed', Outcome::CONFIG, 2, FALSE],
      'php not allowed' => ['php_not_allowed', Outcome::CONFIG, 2, FALSE],
      'no image' => ['no_image', Outcome::CONFIG, 2, FALSE],
      'ambiguous image' => ['ambiguous_image', Outcome::CONFIG, 2, FALSE],
      'engine failed' => ['engine_failed', Outcome::INFRA, 70, TRUE],
      'internal error' => ['internal_error', Outcome::INFRA, 70, TRUE],
      'cancelled' => ['cancelled', Outcome::INFRA, 70, TRUE],
      'container failed' => ['container_failed', Outcome::INFRA, 70, TRUE],
      'the run timed out' => ['timeout', Outcome::INFRA, 70, FALSE],
      'out of memory' => ['oom_killed', Outcome::INFRA, 70, FALSE],
      'killed' => ['killed', Outcome::INFRA, 70, FALSE],
      'a broken policy' => ['policy_invalid', Outcome::INFRA, 70, FALSE],
      'a code from a newer server' => ['something_new', Outcome::INFRA, 70, FALSE],
    ];
  }

  public function testResourceLimitsAreNotRetriedAndSayWhy(): void {
    $o = (new ResultClassifier())->classify(new TransportResult(70, $this->body('error', 'timeout'), ''));
    $this->assertFalse($o->retryable);
    $this->assertStringContainsString('would hit it again', $o->hint);
  }

  public function testSshFailures(): void {
    $o = (new ResultClassifier())->classify(new TransportResult(255, '', "ssh: connect to host box port 22: Connection refused\n"));
    $this->assertSame([Outcome::INFRA, TRUE], [$o->kind, $o->retryable]);
    $this->assertStringContainsString('Connection refused', $o->reason);
    $this->assertStringContainsString('phorward doctor', $o->hint);
    $o = (new ResultClassifier())->classify(new TransportResult(NULL, '', '', TRUE));
    $this->assertSame([Outcome::INFRA, TRUE, 70], [$o->kind, $o->retryable, $o->exitCode()]);
  }

  public function testNoPhorwardRunForThisKeyIsASetupProblemNotATransientOne(): void {
    $o = (new ResultClassifier())->classify(new TransportResult(127, '', "bash: phorward-run: command not found\n"));
    $this->assertSame([Outcome::CONFIG, FALSE, 2], [$o->kind, $o->retryable, $o->exitCode()]);
    $this->assertStringContainsString('server:install', $o->hint);
  }

  public function testAReplyThatIsNotAResultIsTransient(): void {
    foreach (['Welcome to Ubuntu', '', '{"truncated": '] as $stdout) {
      $o = (new ResultClassifier())->classify(new TransportResult(0, $stdout, ''));
      $this->assertSame([Outcome::INFRA, TRUE], [$o->kind, $o->retryable], $stdout);
    }
  }

  public function testMalformedResults(): void {
    $this->assertSame(Outcome::INFRA, (new ResultClassifier())->classify(new TransportResult(0, json_encode(['protocol' => 1, 'verdict' => 'passed']), ''))->kind, 'no checks list');
    $this->assertSame(Outcome::INFRA, (new ResultClassifier())->classify(new TransportResult(0, json_encode(['protocol' => 1, 'verdict' => 'maybe']), ''))->kind);
    $this->assertSame(Outcome::INFRA, (new ResultClassifier())->classify(new TransportResult(0, json_encode(['protocol' => 1]), ''))->kind);
  }

  public function testAServerOnAnotherProtocolIsASetupProblem(): void {
    $o = (new ResultClassifier())->classify(new TransportResult(0, json_encode(['protocol' => 2, 'verdict' => 'passed', 'checks' => []]), ''));
    $this->assertSame([Outcome::CONFIG, 2], [$o->kind, $o->exitCode()]);
    $this->assertStringContainsString('protocol 2', $o->reason);
  }

  public function testWhatTheServerSaysIsMadeSafeToPrint(): void {
    $doc = ['protocol' => 1, 'verdict' => 'error', 'checks' => [], 'error' => ['code' => 'engine_failed', 'message' => "\e[2Jcleared\e]0;title\x07 ok"]];
    $o = (new ResultClassifier())->classify(new TransportResult(70, json_encode($doc), ''));
    $this->assertStringNotContainsString("\e", $o->reason);
    $this->assertStringContainsString('cleared', $o->reason);
    $o = (new ResultClassifier())->classify(new TransportResult(255, '', "\e[31mred\e[0m\n"));
    $this->assertStringNotContainsString("\e", $o->reason);
  }

  public function testAnErrorThatIsNotAnObjectDoesNotBreakIt(): void {
    $o = (new ResultClassifier())->classify(new TransportResult(70, json_encode(['protocol' => 1, 'verdict' => 'error', 'error' => 'oops']), ''));
    $this->assertSame(Outcome::INFRA, $o->kind);
  }

}
