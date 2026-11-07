<?php

declare(strict_types=1);

namespace Phorward\Run;

use Phorward\Report\Sanitize;
use Phorward\Server\Protocol;
use Phorward\Ssh\TransportResult;

/**
 * Decides what a reply from the server means. The line that matters most is the one between "the code is wrong"
 * (failed) and "the machinery is wrong" (everything else): a caller that treats an infrastructure error as a
 * failure tells a model its code is broken when a server fell over.
 */
final class ResultClassifier {

  /** The request or setup is wrong. */
  private const CONFIG_CODES = ['bad_request', 'bad_archive', 'protocol_mismatch', 'profile_not_allowed', 'php_not_allowed',
    'no_image', 'ambiguous_image'];

  /** Worth another go: the failure says nothing about the code or the setup. */
  private const TRANSIENT_CODES = ['engine_failed', 'internal_error', 'cancelled', 'container_failed'];

  /** The run hit a limit. A retry would hit it again, so it is reported rather than repeated. */
  private const LIMIT_CODES = ['timeout', 'oom_killed', 'killed'];

  private const DOCTOR = 'Run "phorward doctor" to check the connection and the server.';

  public function classify(TransportResult $reply): Outcome {
    if ($reply->timedOut) {
      return new Outcome(Outcome::INFRA, NULL, 'the run did not finish in time', TRUE, self::DOCTOR);
    }
    if ($reply->exitCode === 255) {
      return new Outcome(Outcome::INFRA, NULL, 'ssh could not connect: ' . $this->line($reply->stderr), TRUE, self::DOCTOR);
    }
    $result = Protocol::decode($reply->stdout);
    if ($result === NULL) {
      if ($reply->exitCode === 127 || stripos($reply->stderr, 'not found') !== FALSE) {
        return new Outcome(Outcome::CONFIG, NULL, 'the server has no phorward-run for this key', FALSE,
          'Install the server side with "phorward server:install" and use the restricted key (docs/ssh-access.md). ' . self::DOCTOR);
      }
      return new Outcome(Outcome::INFRA, NULL, 'the server did not answer with a phorward result: '
        . $this->line($reply->stdout . $reply->stderr), TRUE, self::DOCTOR);
    }
    if (($result['protocol'] ?? NULL) !== Protocol::VERSION && ($result['error']['code'] ?? '') !== 'protocol_mismatch') {
      return new Outcome(Outcome::CONFIG, $result, 'the server speaks protocol ' . $this->line((string) json_encode($result['protocol'] ?? NULL))
        . ', this client speaks ' . Protocol::VERSION, FALSE, 'Update the older side so both come from one release. ' . self::DOCTOR);
    }
    $verdict = $result['verdict'] ?? NULL;
    if ($verdict === 'passed' || $verdict === 'failed') {
      if (!is_array($result['checks'] ?? NULL)) {
        return new Outcome(Outcome::INFRA, $result, 'the result has no list of checks', TRUE, self::DOCTOR);
      }
      return new Outcome($verdict === 'passed' ? Outcome::PASSED : Outcome::FAILED, $result, $verdict);
    }
    if ($verdict !== 'error') {
      return new Outcome(Outcome::INFRA, $result, 'the result has no verdict', FALSE, self::DOCTOR);
    }
    $code = is_string($result['error']['code'] ?? NULL) ? $result['error']['code'] : 'unknown';
    $message = $this->line((string) ($result['error']['message'] ?? ''));
    $reason = $code . ($message !== '' ? ": $message" : '');
    if ($code === 'busy') {
      return new Outcome(Outcome::BUSY, $result, 'the server is busy: every run slot is taken', TRUE, NULL, $code);
    }
    if (in_array($code, self::CONFIG_CODES, TRUE)) {
      return new Outcome(Outcome::CONFIG, $result, $reason, FALSE, $this->configHint($code), $code);
    }
    if (in_array($code, self::TRANSIENT_CODES, TRUE)) {
      return new Outcome(Outcome::INFRA, $result, $reason, TRUE, self::DOCTOR, $code);
    }
    if (in_array($code, self::LIMIT_CODES, TRUE)) {
      return new Outcome(Outcome::INFRA, $result, $reason, FALSE,
        'The run hit a resource limit, so it was not retried: it would hit it again. Look for a runaway test, or raise '
        . 'limits.timeout or limits.memory (the server policy caps them).', $code);
    }
    return new Outcome(Outcome::INFRA, $result, $reason, FALSE, self::DOCTOR, $code);
  }

  private function configHint(string $code): string {
    return match ($code) {
      'profile_not_allowed' => 'The server policy does not allow this profile (allowed_profiles). ' . self::DOCTOR,
      'php_not_allowed' => 'The server policy does not allow this PHP version (allowed_php). ' . self::DOCTOR,
      'no_image' => 'The server has no image for this project. Build one with "phorward server:install". ' . self::DOCTOR,
      'ambiguous_image' => 'Several images match; set "module" in phorward.json.',
      'bad_archive' => 'The server refused the uploaded tree; see sync.exclude, and docs/protocol.md for what it refuses.',
      'protocol_mismatch' => 'Update the older side so both come from one release. ' . self::DOCTOR,
      'bad_request' => 'The server refused the request as sent (see the reason above). Check the options you gave, for example --tests; '
        . 'if they are right, the client and the server may be from different releases. ' . self::DOCTOR,
      default => self::DOCTOR,
    };
  }

  /**
   * The first non-empty line of something the server said, made safe to print.
   */
  private function line(string $text): string {
    foreach (preg_split('/\R/', Sanitize::terminal($text)) ?: [] as $line) {
      if (trim($line) !== '') {
        return mb_substr(trim($line), 0, 200);
      }
    }
    return '';
  }

}
