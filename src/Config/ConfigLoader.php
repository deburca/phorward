<?php

declare(strict_types=1);

namespace Phorward\Config;

use Phorward\Project\Detector;

/**
 * Resolves configuration from five layers, highest first: flags, PHORWARD_* environment variables, the project
 * file, the user file, defaults. Project and user files may only hold settings of their own scope.
 */
final class ConfigLoader {

  private const SERVER_SIDE_ENV = ['PHORWARD_CONFIG', 'PHORWARD_POLICY', 'PHORWARD_ENGINE', 'PHORWARD_MODULE_DIR'];

  public function __construct(private readonly Schema $schema) {}

  /**
   * @param array<string, string> $env The process environment.
   * @param array<string, string|bool> $flags Config flags as given, keyed by flag name without dashes.
   */
  public function load(string $cwd, array $env, array $flags = []): ResolvedConfig {
    $values = [];
    $sources = [];
    foreach ($this->schema->all() as $key => $setting) {
      $values[$key] = $setting->default;
      $sources[$key] = 'default';
    }
    $warnings = [];

    // 5 -> 1, lowest priority first, so each layer overwrites the one before.
    [$userFile, $userData] = $this->readUserFile($env);
    foreach ($userData as $key => $value) {
      $values[$key] = $value;
      $sources[$key] = "user file $userFile";
    }

    $root = $this->findProjectRoot($cwd);
    $projectFile = NULL;
    if ($root !== NULL) {
      [$projectFile, $label, $projectData] = $this->readProjectFile($root);
      foreach ($projectData as $key => $value) {
        $values[$key] = $value;
        $sources[$key] = $label;
      }
    }

    foreach ($this->schema->all() as $key => $setting) {
      $name = $setting->envName();
      if (isset($env[$name]) && $env[$name] !== '') {
        $values[$key] = $setting->fromText($env[$name], "environment $name");
        $sources[$key] = "environment $name";
      }
    }
    foreach (array_keys($env) as $name) {
      if (str_starts_with((string) $name, 'PHORWARD_') && !in_array($name, self::SERVER_SIDE_ENV, TRUE)
        && !$this->isKnownEnvName((string) $name)) {
        $warnings[] = "Unknown environment variable $name was ignored.";
      }
    }

    foreach ($flags as $flag => $value) {
      $setting = $this->schema->byFlag((string) $flag);
      if ($setting === NULL) {
        throw new ConfigException("Unknown option --$flag.");
      }
      $origin = "flag --$flag";
      if (is_bool($value) && $setting->type !== Type::Bool) {
        throw new ConfigException("$origin needs a value.");
      }
      $values[$setting->key] = is_bool($value) ? $value : $setting->fromText($value, $origin);
      $sources[$setting->key] = $origin;
    }

    // Things the client can work out for itself, used only when nothing above set them.
    if ($root !== NULL && ($values['profile'] === NULL || $values['module'] === NULL)) {
      $found = Detector::detect($root);
      foreach (['profile', 'module'] as $key) {
        if ($values[$key] === NULL && $found[$key] !== NULL) {
          $values[$key] = $found[$key];
          $sources[$key] = "detected ({$found[$key . '_why']})";
        }
      }
    }

    return new ResolvedConfig($values, $sources, $root, $projectFile, $userFile, $warnings);
  }

  private function isKnownEnvName(string $name): bool {
    foreach ($this->schema->all() as $setting) {
      if ($setting->envName() === $name) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array{0: ?string, 1: array<string, mixed>} The path used (if the file exists) and its settings.
   */
  private function readUserFile(array $env): array {
    $explicit = $env['PHORWARD_CONFIG'] ?? '';
    if ($explicit !== '') {
      $path = $explicit;
    }
    else {
      $base = ($env['XDG_CONFIG_HOME'] ?? '') !== '' ? $env['XDG_CONFIG_HOME'] : (($env['HOME'] ?? '') !== '' ? $env['HOME'] . '/.config' : '');
      $path = $base === '' ? '' : $base . '/phorward/config.json';
    }
    if ($path === '' || !is_file($path)) {
      if ($explicit !== '') {
        throw new ConfigException("PHORWARD_CONFIG names $explicit, which does not exist.");
      }
      return [NULL, []];
    }
    $data = $this->decodeFile($path, $path);
    return [$path, $this->flatten($data, Scope::User, "user file $path")];
  }

  /**
   * @return array{0: ?string, 1: string, 2: array<string, mixed>} The file (if any), how to name it, and its settings.
   */
  private function readProjectFile(string $root): array {
    $own = $root . '/phorward.json';
    $composer = $root . '/composer.json';
    $extra = NULL;
    if (is_file($composer)) {
      $json = $this->decodeFile($composer, $composer);
      if (isset($json['extra']['phorward'])) {
        $extra = $json['extra']['phorward'];
        if (!is_array($extra) || ($extra !== [] && array_is_list($extra))) {
          throw new ConfigException("$composer: extra.phorward must be an object.");
        }
      }
    }
    if (is_file($own) && $extra !== NULL) {
      throw new ConfigException("Both $own and extra.phorward in $composer exist. Keep one; two project files would "
        . 'leave it unclear which applies.');
    }
    if (is_file($own)) {
      return [$own, "project file $own", $this->flatten($this->decodeFile($own, $own), Scope::Project, "project file $own")];
    }
    if ($extra !== NULL) {
      return [$composer, "composer.json extra.phorward ($composer)", $this->flatten($extra, Scope::Project, "$composer extra.phorward")];
    }
    return [NULL, 'default', []];
  }

  /**
   * @return array<string, mixed>
   */
  private function decodeFile(string $path, string $label): array {
    $text = @file_get_contents($path);
    if ($text === FALSE) {
      throw new ConfigException("Cannot read $label.");
    }
    $data = json_decode($text, TRUE);
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
      throw new ConfigException("$label is not a JSON object" . (json_last_error() !== JSON_ERROR_NONE ? ': ' . json_last_error_msg() : '') . '.');
    }
    return $data;
  }

  /**
   * Turns nested JSON into dotted keys, validating each against the schema and the file's scope.
   *
   * @return array<string, mixed>
   */
  private function flatten(array $data, Scope $scope, string $origin, string $prefix = ''): array {
    $flat = [];
    foreach ($data as $name => $value) {
      $key = $prefix . $name;
      $setting = $this->schema->find($key);
      if ($setting !== NULL) {
        if ($setting->scope !== $scope) {
          throw new ConfigException($this->wrongScopeMessage($setting, $scope, $origin));
        }
        $flat[$key] = $setting->validate($value, $origin);
      }
      elseif ($this->schema->isGroup($key) && is_array($value) && ($value === [] || !array_is_list($value))) {
        $flat += $this->flatten($value, $scope, $origin, $key . '.');
      }
      else {
        throw new ConfigException($this->unknownKeyMessage($key, $origin));
      }
    }
    return $flat;
  }

  private function wrongScopeMessage(Setting $setting, Scope $in, string $origin): string {
    $env = $setting->envName();
    if ($in === Scope::Project) {
      return "$origin: \"{$setting->key}\" is a user setting; put it in ~/.config/phorward/config.json or set $env, "
        . 'not in the project file. A repository must not choose where your code is sent or how you run.';
    }
    return "$origin: \"{$setting->key}\" is a project setting; put it in phorward.json (or extra.phorward in composer.json) "
      . "or set $env, not in the user file.";
  }

  private function unknownKeyMessage(string $key, string $origin): string {
    if (preg_match('/(pass(word)?|token|secret|credential|(^|[._-])keys?($|[._-]))/i', $key)) {
      return "$origin: \"$key\" is not a phorward setting. No phorward setting holds a secret: use your SSH "
        . 'configuration or a secrets manager.';
    }
    $near = $this->schema->nearest($key);
    return "$origin: unknown setting \"$key\"" . ($near !== NULL ? " (did you mean \"$near\"?)" : '') . '.';
  }

  private function findProjectRoot(string $cwd): ?string {
    $dir = realpath($cwd);
    while ($dir !== FALSE && $dir !== '') {
      if (is_file($dir . '/phorward.json') || is_file($dir . '/composer.json')) {
        return $dir;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        return NULL;
      }
      $dir = $parent;
    }
    return NULL;
  }

}
