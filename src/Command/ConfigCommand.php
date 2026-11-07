<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Config\Schema;

/**
 * "phorward config": prints every resolved setting and where its value came from.
 */
final class ConfigCommand {

  public function __construct(
    private readonly Schema $schema,
    private readonly ConfigLoader $loader,
    private readonly Output $output,
  ) {}

  /**
   * @param string[] $positional
   * @param array<string, string|bool> $options
   * @param array<string, string> $env
   */
  public function run(array $positional, array $options, array $env, string $cwd): int {
    if ($positional !== []) {
      throw new ConfigException('"config" takes no arguments, only settings given as options.');
    }
    $format = 'text';
    if (isset($options['output'])) {
      $format = $options['output'];
      unset($options['output']);
      if (!in_array($format, ['text', 'json'], TRUE)) {
        throw new ConfigException('--output must be text or json.');
      }
    }
    // Everything else is a setting given as a flag.
    $config = $this->loader->load($cwd, $env, $options);

    if ($format === 'json') {
      $values = [];
      foreach ($this->schema->all() as $key => $setting) {
        $values[$key] = ['value' => $config->get($key), 'source' => $config->source($key), 'scope' => $setting->scope->value];
      }
      $this->output->line(json_encode([
        'project_root' => $config->projectRoot,
        'project_file' => $config->projectFile,
        'user_file' => $config->userFile,
        'values' => $values,
        'warnings' => $config->warnings,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      return Application::EXIT_OK;
    }

    $this->output->line('project root: ' . ($config->projectRoot ?? '(none found)'));
    $this->output->line('project file: ' . ($config->projectFile ?? '(none)'));
    $this->output->line('user file:    ' . ($config->userFile ?? '(none)'));
    $this->output->line();
    $width = max(array_map('strlen', array_keys($this->schema->all())));
    foreach ($this->schema->all() as $key => $setting) {
      $this->output->line(sprintf("%-{$width}s  %-18s  %s", $key, $this->show($config->get($key)), $config->source($key)));
    }
    foreach ($config->warnings as $warning) {
      $this->output->line();
      $this->output->line("warning: $warning");
    }
    return Application::EXIT_OK;
  }

  private function show(mixed $value): string {
    return match (TRUE) {
      $value === NULL => '(unset)',
      is_bool($value) => $value ? 'true' : 'false',
      is_array($value) => $value === [] ? '[]' : implode(',', $value),
      default => (string) $value,
    };
  }

}
