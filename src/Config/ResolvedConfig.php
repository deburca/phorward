<?php

declare(strict_types=1);

namespace Phorward\Config;

/**
 * The outcome of loading configuration: every key's value and where it came from.
 */
final class ResolvedConfig {

  /**
   * @param array<string, mixed> $values
   * @param array<string, string> $sources
   * @param string[] $warnings
   */
  public function __construct(
    private readonly array $values,
    private readonly array $sources,
    public readonly ?string $projectRoot,
    public readonly ?string $projectFile,
    public readonly ?string $userFile,
    public readonly array $warnings = [],
  ) {}

  public function get(string $key): mixed {
    return $this->values[$key] ?? NULL;
  }

  public function source(string $key): string {
    return $this->sources[$key] ?? 'unset';
  }

  /**
   * @return array<string, mixed>
   */
  public function values(): array {
    return $this->values;
  }

  /**
   * @return array<string, string>
   */
  public function sources(): array {
    return $this->sources;
  }

}
