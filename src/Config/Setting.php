<?php

declare(strict_types=1);

namespace Phorward\Config;

/**
 * One configuration key: its scope, type, default and the rules a value must meet.
 */
final class Setting {

  public function __construct(
    public readonly string $key,
    public readonly Scope $scope,
    public readonly Type $type,
    public readonly string $description,
    public readonly mixed $default = NULL,
    public readonly ?array $choices = NULL,
    public readonly ?string $pattern = NULL,
    public readonly int|float|NULL $min = NULL,
    public readonly int|float|NULL $max = NULL,
    public readonly ?string $patternHint = NULL,
  ) {}

  /**
   * The environment variable that sets this key: PHORWARD_ plus the key in upper case, dots as underscores.
   */
  public function envName(): string {
    return 'PHORWARD_' . strtoupper(str_replace('.', '_', $this->key));
  }

  /**
   * The command-line flag that sets this key, without the leading dashes.
   */
  public function flagName(): string {
    return str_replace(['.', '_'], '-', $this->key);
  }

  /**
   * Turns text from the environment or a flag into a value of this setting's type, then validates it.
   */
  public function fromText(string $text, string $origin): mixed {
    $text = trim($text);
    $raw = match ($this->type) {
      Type::StringList => $text === '' ? [] : array_map('trim', explode(',', $text)),
      Type::Bool => match (strtolower($text)) {
        '1', 'true', 'yes', 'on' => TRUE,
        '0', 'false', 'no', 'off' => FALSE,
        default => throw new ConfigException("$origin: {$this->key} must be true or false, got \"$text\"."),
      },
      Type::Int => ctype_digit(ltrim($text, '-')) && $text !== '' ? (int) $text : $text,
      Type::Number => is_numeric($text) ? $text + 0 : $text,
      Type::String => $text,
    };
    return $this->validate($raw, $origin);
  }

  /**
   * Checks a value that already has a type (from JSON) and returns it normalised.
   */
  public function validate(mixed $value, string $origin): mixed {
    $where = "$origin: {$this->key}";
    switch ($this->type) {
      case Type::Bool:
        if (!is_bool($value)) {
          throw new ConfigException("$where must be true or false.");
        }
        return $value;

      case Type::Int:
      case Type::Number:
        if (is_bool($value) || !is_int($value) && !($this->type === Type::Number && is_float($value))) {
          throw new ConfigException("$where must be " . ($this->type === Type::Int ? 'a whole number' : 'a number') . '.');
        }
        if ($this->min !== NULL && $value < $this->min || $this->max !== NULL && $value > $this->max) {
          throw new ConfigException("$where must be between {$this->min} and {$this->max}, got $value.");
        }
        return $value;

      case Type::StringList:
        if (!is_array($value) || !array_is_list($value)) {
          throw new ConfigException("$where must be a list of strings.");
        }
        foreach ($value as $item) {
          $this->validateString($item, $where);
        }
        if ($this->choices !== NULL && $value === []) {
          throw new ConfigException("$where must not be empty (choose from " . implode(', ', $this->choices) . ').');
        }
        if (count($value) !== count(array_unique($value))) {
          throw new ConfigException("$where lists the same value twice.");
        }
        return $value;

      case Type::String:
        $this->validateString($value, $where);
        return $value;
    }
  }

  private function validateString(mixed $value, string $where): void {
    if (!is_string($value) || $value === '') {
      throw new ConfigException("$where must be a non-empty string.");
    }
    if ($this->choices !== NULL && !in_array($value, $this->choices, TRUE)) {
      throw new ConfigException("$where must be one of " . implode(', ', $this->choices) . ", got \"$value\".");
    }
    if ($this->pattern !== NULL && !preg_match($this->pattern, $value)) {
      throw new ConfigException("$where is not valid" . ($this->patternHint ? ": {$this->patternHint}" : '') . ", got \"$value\".");
    }
  }

}
