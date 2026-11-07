<?php

declare(strict_types=1);

namespace Phorward\Project;

/**
 * What this package knows about the image profiles it ships (profiles/<name>/manifest.json). A profile the
 * server has but this package does not is simply unknown here, and the checks that need it are skipped.
 */
final class ProfileCatalog {

  public function __construct(private readonly string $profilesDir) {}

  public static function bundled(): self {
    return new self(dirname(__DIR__, 2) . '/profiles');
  }

  /**
   * The file in the project whose hash is recorded in the image, or NULL if the profile is not known here.
   */
  public function staleHashFile(string $profile): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $profile)) {
      return NULL;
    }
    $path = $this->profilesDir . '/' . $profile . '/manifest.json';
    $manifest = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
    $file = is_array($manifest) ? ($manifest['build']['stale_hash_file'] ?? NULL) : NULL;
    return is_string($file) && $file !== '' ? $file : NULL;
  }

  /**
   * The PHP version the profile uses when none is asked for, or NULL if the profile is not known here.
   */
  public function defaultPhp(string $profile): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $profile)) {
      return NULL;
    }
    $path = $this->profilesDir . '/' . $profile . '/manifest.json';
    $manifest = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
    $php = is_array($manifest) ? ($manifest['defaults']['php'] ?? NULL) : NULL;
    return is_string($php) && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}$/', $php) ? $php : NULL;
  }

  /**
   * The hash an image built from this project would carry, or NULL when it cannot be worked out.
   */
  public function projectHash(string $profile, string $projectRoot): ?string {
    $file = $this->staleHashFile($profile);
    if ($file === NULL || !is_file($projectRoot . '/' . $file)) {
      return NULL;
    }
    return hash_file('sha256', $projectRoot . '/' . $file) ?: NULL;
  }

  /**
   * What "phorward verify" needs to know about a profile, or NULL if the profile is not known here: the test
   * layout, the PHP versions it supports, the checks it offers, and the security patterns to scan added lines for.
   *
   * @return array{layout: string, php: string[], checks: string[], source_extensions: string[], scan_extensions: string[], fail: array<int, array{pattern: string, message: string}>, warn: array<int, array{pattern: string, message: string}>}|null
   */
  public function verifySpec(string $profile): ?array {
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $profile)) {
      return NULL;
    }
    $path = $this->profilesDir . '/' . $profile . '/manifest.json';
    $manifest = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
    if (!is_array($manifest)) {
      return NULL;
    }
    $strings = static fn(mixed $list): array => is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    $patterns = static function (mixed $list): array {
      $good = [];
      foreach (is_array($list) ? $list : [] as $entry) {
        if (is_array($entry) && is_string($entry['pattern'] ?? NULL) && is_string($entry['message'] ?? NULL)
          && @preg_match('~' . str_replace('~', '\\~', $entry['pattern']) . '~', '') !== FALSE) {
          $good[] = ['pattern' => $entry['pattern'], 'message' => $entry['message']];
        }
      }
      return $good;
    };
    $verify = is_array($manifest['verify'] ?? NULL) ? $manifest['verify'] : [];
    $php = array_values(array_filter($strings($manifest['versions']['php'] ?? []), fn($v) => preg_match('/^[0-9]{1,2}\.[0-9]{1,2}$/', $v)));
    usort($php, 'version_compare');
    return [
      'layout' => ($manifest['test_layout'] ?? '') === 'flat' ? 'flat' : 'drupal',
      'php' => $php,
      'checks' => array_keys(is_array($manifest['checks'] ?? NULL) ? $manifest['checks'] : []),
      'source_extensions' => $strings($verify['source_extensions'] ?? ['php']),
      'scan_extensions' => $strings($verify['scan_extensions'] ?? ['php']),
      'fail' => $patterns($verify['security']['fail'] ?? []),
      'warn' => $patterns($verify['security']['warn'] ?? []),
    ];
  }

}
