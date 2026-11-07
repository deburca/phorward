<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * How one gate came out. A gate that could not run because the machinery failed is INFRA, never FAILED: a caller
 * must not tell a model its code is wrong when a server fell over.
 */
final class GateResult {

  public const PASSED = 'passed';
  public const FAILED = 'failed';
  public const SKIPPED = 'skipped';
  public const INFRA = 'infra';

  /**
   * @param string $where "local" or "server".
   * @param string|null $problem For INFRA: the run's outcome kind (config, busy or infra), which sets the exit status.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $status,
    public readonly string $where,
    public readonly string $summary = '',
    public readonly string $detail = '',
    public readonly ?int $durationMs = NULL,
    public readonly ?string $problem = NULL,
    public readonly ?string $hint = NULL,
  ) {}

  public function withFailure(string $summary, string $detail): self {
    return new self($this->name, self::FAILED, $this->where, $summary, $detail, $this->durationMs, NULL, NULL);
  }

}
