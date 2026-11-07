<?php

declare(strict_types=1);

namespace Phorward\Command;

/**
 * One line of the doctor report.
 */
final class Check {

  public const OK = 'ok';
  public const WARN = 'warn';
  public const FAIL = 'fail';

  public function __construct(
    public readonly string $name,
    public readonly string $status,
    public readonly string $detail,
    public readonly ?string $fix = NULL,
  ) {}

  public static function ok(string $name, string $detail): self {
    return new self($name, self::OK, $detail);
  }

  public static function warn(string $name, string $detail, string $fix): self {
    return new self($name, self::WARN, $detail, $fix);
  }

  public static function fail(string $name, string $detail, string $fix): self {
    return new self($name, self::FAIL, $detail, $fix);
  }

}
