<?php

declare(strict_types=1);

namespace Phorward\Project;

/**
 * Works out what kind of project a directory holds, the way server/build-image does, so the client and the
 * image builder agree on the profile and on the project's short name.
 */
final class Detector {

  /**
   * @return array{profile: ?string, profile_why: ?string, module: ?string, module_why: ?string}
   */
  public static function detect(string $root): array {
    $result = ['profile' => NULL, 'profile_why' => NULL, 'module' => NULL, 'module_why' => NULL];
    $infos = glob($root . '/*.info.yml') ?: [];
    if ($infos !== []) {
      $result['profile'] = 'drupal-module';
      $result['profile_why'] = 'found ' . basename($infos[0]);
      if (count($infos) === 1) {
        $result['module'] = basename($infos[0], '.info.yml');
        $result['module_why'] = 'from ' . basename($infos[0]);
      }
      return $result;
    }
    $package = self::packageName($root);
    if ($package !== NULL) {
      $result['profile'] = 'php-library';
      $result['profile_why'] = 'no *.info.yml; composer.json present';
      $result['module'] = self::shortName($package);
      $result['module_why'] = "from the package name $package";
    }
    return $result;
  }

  /**
   * vendor/some-name becomes some_name: lowercase letters, digits and underscores, starting with a letter.
   */
  public static function shortName(string $package): string {
    $name = strtolower(substr($package, (int) strrpos('/' . $package, '/')));
    $name = preg_replace('/[^a-z0-9_]/', '_', $name) ?? $name;
    return preg_match('/^[a-z]/', $name) ? $name : 'p_' . $name;
  }

  private static function packageName(string $root): ?string {
    $file = $root . '/composer.json';
    if (!is_file($file)) {
      return NULL;
    }
    $data = json_decode((string) file_get_contents($file), TRUE);
    $name = is_array($data) ? ($data['name'] ?? NULL) : NULL;
    return is_string($name) && $name !== '' ? $name : NULL;
  }

}
