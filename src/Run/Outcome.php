<?php

declare(strict_types=1);

namespace Phorward\Run;

use Phorward\Cli\Application;

/**
 * What one attempt came to, in the terms the caller acts on.
 */
final class Outcome {

  public const PASSED = 'passed';
  public const FAILED = 'failed';
  /** The request or the setup is wrong; nothing a retry would fix. */
  public const CONFIG = 'config';
  /** The machinery failed, not the code under test. */
  public const INFRA = 'infra';
  /** The server had no free run slot. */
  public const BUSY = 'busy';

  /**
   * @param array<string, mixed>|null $result The server's result document, when there was one.
   */
  public function __construct(
    public readonly string $kind,
    public readonly ?array $result,
    public readonly string $reason,
    public readonly bool $retryable = FALSE,
    public readonly ?string $hint = NULL,
    public readonly ?string $code = NULL,
  ) {}

  public function exitCode(): int {
    return match ($this->kind) {
      self::PASSED => Application::EXIT_OK,
      self::FAILED => Application::EXIT_FAILED,
      self::CONFIG => Application::EXIT_USAGE,
      self::BUSY => Application::EXIT_BUSY,
      default => Application::EXIT_INFRASTRUCTURE,
    };
  }

}
