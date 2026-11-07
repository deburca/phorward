<?php

declare(strict_types=1);

namespace Phorward\Config;

/**
 * The set of known configuration keys. One place knows every key, its scope and its rules; later features
 * (the verify command's gates, for one) register their keys here rather than reading files themselves.
 */
final class Schema {

  /** @var array<string, Setting> */
  private array $settings = [];

  public static function standard(): self {
    $schema = new self();
    $name = '/^[a-z][a-z0-9_]{0,60}$/';
    $checks = ['lint', 'phpcs', 'phpstan', 'phpunit'];

    // Project settings: committed with the project.
    $schema->add(new Setting('profile', Scope::Project, Type::String, 'Image profile; detected when unset', NULL, NULL,
      '/^[a-z0-9][a-z0-9_-]{0,40}$/', NULL, NULL, 'lowercase letters, digits, - and _'));
    $schema->add(new Setting('php', Scope::Project, Type::String, 'PHP version of the image to run on; the profile default when unset',
      NULL, NULL, '/^[0-9]{1,2}\.[0-9]{1,2}$/', NULL, NULL, 'a version such as 8.3'));
    $schema->add(new Setting('module', Scope::Project, Type::String, 'Short name of the project, which selects the image; derived when unset',
      NULL, NULL, $name, NULL, NULL, 'lowercase letters, digits and underscores, starting with a letter'));
    $schema->add(new Setting('checks', Scope::Project, Type::StringList, 'Checks to run by default; everything the profile offers when unset',
      NULL, $checks));
    $schema->add(new Setting('database', Scope::Project, Type::String, 'Database for phpunit; the profile default when unset',
      NULL, ['mariadb', 'sqlite', 'none']));
    $schema->add(new Setting('dev_packages', Scope::Project, Type::StringList, 'Extra dev dependencies baked into a drupal-module image',
      [], NULL, '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', NULL, NULL, 'a Composer package name such as drupal/key'));
    $schema->add(new Setting('sync.exclude', Scope::Project, Type::StringList, 'Globs left out of the uploaded tree, in addition to the built-in ones',
      [], NULL, '/^[^\x00-\x1f]{1,200}$/', NULL, NULL, 'a path or glob on one line'));
    $schema->add(new Setting('sync.include_vendor', Scope::Project, Type::Bool, 'Send vendor/ with the tree', FALSE));
    $schema->add(new Setting('limits.memory', Scope::Project, Type::String, 'Memory to ask the server for, e.g. 4g; the server may lower it',
      NULL, NULL, '/^[0-9]{1,6}[kmg]$/', NULL, NULL, 'a size such as 512m or 4g'));
    $schema->add(new Setting('limits.cpus', Scope::Project, Type::Number, 'CPUs to ask for; the server may lower it', NULL, NULL, NULL, 0.1, 64));
    $schema->add(new Setting('limits.pids', Scope::Project, Type::Int, 'Processes to ask for; the server may lower it', NULL, NULL, NULL, 1, 1000000));
    $schema->add(new Setting('limits.timeout', Scope::Project, Type::Int, 'Wall-clock seconds to ask for; the server may lower it',
      NULL, NULL, NULL, 1, 86400));

    // User settings: one person's setup, never committed.
    $schema->add(new Setting('host', Scope::User, Type::String, 'The server: an SSH alias or user@host', NULL, NULL,
      '/^[A-Za-z0-9._@][A-Za-z0-9._@-]{0,252}$/', NULL, NULL, 'letters, digits, . _ @ and -, not starting with -'));
    $schema->add(new Setting('format', Scope::User, Type::String, 'How results are printed', 'human', ['human', 'json', 'junit']));
    $schema->add(new Setting('retries', Scope::User, Type::Int, 'Attempts after an infrastructure failure or a busy server', 3, NULL, NULL, 0, 10));
    $schema->add(new Setting('backoff', Scope::User, Type::Number, 'Seconds before the first retry; doubles each time, at most 30', 2, NULL, NULL, 0, 30));
    $schema->add(new Setting('wait', Scope::User, Type::Int, 'Longest total time, in seconds, to keep retrying a busy server', 120, NULL, NULL, 0, 3600));
    $schema->add(new Setting('connect_timeout', Scope::User, Type::Int, 'SSH connection timeout in seconds', 10, NULL, NULL, 1, 120));
    return $schema;
  }

  public function add(Setting $setting): void {
    if (isset($this->settings[$setting->key])) {
      throw new \LogicException("Setting {$setting->key} is registered twice.");
    }
    $this->settings[$setting->key] = $setting;
  }

  public function find(string $key): ?Setting {
    return $this->settings[$key] ?? NULL;
  }

  /**
   * @return array<string, Setting>
   */
  public function all(): array {
    return $this->settings;
  }

  /**
   * True when $prefix names a group of keys, such as "sync" for "sync.exclude".
   */
  public function isGroup(string $prefix): bool {
    foreach (array_keys($this->settings) as $key) {
      if (str_starts_with($key, $prefix . '.')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The valid key closest to an unknown one, or NULL if nothing is close.
   */
  public function nearest(string $unknown): ?string {
    $best = NULL;
    $bestDistance = 4;
    foreach (array_keys($this->settings) as $key) {
      $distance = levenshtein($unknown, $key);
      if ($distance < $bestDistance) {
        [$best, $bestDistance] = [$key, $distance];
      }
    }
    return $best;
  }

  public function byFlag(string $flag): ?Setting {
    foreach ($this->settings as $setting) {
      if ($setting->flagName() === $flag) {
        return $setting;
      }
    }
    return NULL;
  }

}
