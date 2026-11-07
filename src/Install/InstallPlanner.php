<?php

declare(strict_types=1);

namespace Phorward\Install;

use Phorward\Config\ConfigException;
use Phorward\Config\ResolvedConfig;
use Phorward\Project\ProfileCatalog;

/**
 * Works out the steps that install the server side, from validated options and the project's configuration.
 * It runs nothing: the command either prints the plan or applies it.
 */
final class InstallPlanner {

  public const DEFAULT_PREFIX = '/opt/phorward';
  public const DEFAULT_USER = 'phorward';
  public const DEFAULT_POLICY = '/etc/phorward/policy.json';
  public const DEFAULT_RESULTS = '/var/lib/phorward/results';

  public function __construct(
    private readonly ProfileCatalog $catalog,
    private readonly TreeArchiver $archiver,
    private readonly string $packageRoot,
  ) {}

  /**
   * @param array{prefix: string, user: string, policy_file: string, results_dir: string, sudo: bool,
   *   skip_build: bool, skip_smoke: bool, public_key: ?string, from: ?string} $options
   */
  public function plan(array $options, ResolvedConfig $config): InstallPlan {
    $prefix = $options['prefix'];
    $user = $options['user'];
    $sudo = $options['sudo'] ? 'sudo -n ' : '';
    $asUser = $options['sudo'] ? "sudo -n -u $user -H " : '';
    $profile = $config->get('profile');

    $steps = [$this->kitStep($options, $sudo), $this->policyStep($options, $sudo, $profile)];
    $buildable = !$options['skip_build'];
    if ($buildable) {
      $steps[] = $this->buildStep($options, $config, $asUser);
    }
    return new InstallPlan($this->preflight($options, $sudo), $steps, $this->notes($options, $buildable));
  }

  private function preflight(array $options, string $sudo): Step {
    $prefix = Quote::sh($options['prefix']);
    $user = Quote::sh($options['user']);
    $lines = ['set -e'];
    if ($sudo !== '') {
      $lines[] = "sudo -n true || { echo 'sudo needs a password for this login, or sudo is missing' >&2; exit 11; }";
    }
    $lines[] = "id -u $user >/dev/null 2>&1 || { echo 'the user $user does not exist; create it first (docs/ssh-access.md)' >&2; exit 12; }";
    $needs = 'tar python3' . ($options['skip_smoke'] || $options['skip_build'] ? '' : ' rsync');
    $lines[] = "for tool in $needs; do command -v \$tool >/dev/null 2>&1 || { echo \"\$tool is not installed on the server\" >&2; exit 13; }; done";
    $lines[] = "command -v podman >/dev/null 2>&1 || command -v docker >/dev/null 2>&1 || { echo 'neither podman nor docker is installed' >&2; exit 14; }";
    $lines[] = "[ ! -e $prefix ] || [ -L $prefix ] || { echo '$prefix exists and is a directory, not a link to an installed kit; move it aside' >&2; exit 15; }";
    return new Step('Check the server', implode("\n", $lines), NULL, NULL, 60.0);
  }

  private function kitStep(array $options, string $sudo): Step {
    $prefix = Quote::sh($options['prefix']);
    $releases = Quote::sh($options['prefix'] . '.d');
    $id = $this->archiver->contentId($this->packageRoot, ['server', 'profiles']);
    $release = Quote::sh($options['prefix'] . '.d/' . $id);
    $script = implode("\n", [
      'set -e',
      "if [ -d $release ]; then",
      '  cat >/dev/null',
      "  echo 'kit $id is already installed'",
      'else',
      "  {$sudo}install -d -m 755 $releases",
      "  T=\$({$sudo}mktemp -d $releases/.partial.XXXXXX)",
      "  {$sudo}tar -xf - --no-same-owner -C \"\$T\"",
      "  {$sudo}chmod 755 \"\$T\"",
      "  {$sudo}mv \"\$T\" $release",
      "  echo 'kit $id installed'",
      'fi',
      "{$sudo}ln -sfn $release $prefix.tmp",
      "{$sudo}mv -T $prefix.tmp $prefix",
    ]);
    return new Step("Install the server kit at {$options['prefix']} (release $id)", $script,
      fn() => $this->archiver->create($this->packageRoot, [], FALSE, ['server', 'profiles']),
      'the server kit (server/ and profiles/) as a tar archive', 120.0);
  }

  private function policyStep(array $options, string $sudo, ?string $profile): Step {
    $policy = Quote::sh($options['policy_file']);
    $dir = Quote::sh(dirname($options['policy_file']));
    $results = Quote::sh($options['results_dir']);
    $owner = $sudo !== '' ? '-o ' . Quote::sh($options['user']) . ' ' : '';
    $allowed = $profile !== NULL ? [$profile] : ['drupal-module'];
    $content = json_encode(['allowed_profiles' => $allowed, 'results_dir' => $options['results_dir']], JSON_UNESCAPED_SLASHES) . "\n";
    $note = $profile !== NULL ? "grep -q " . Quote::sh('"' . $profile . '"') . " $policy || echo " . Quote::sh("note: $profile is not in allowed_profiles in {$options['policy_file']}; add it") : 'true';
    $script = implode("\n", [
      'set -e',
      "{$sudo}install -d -m 755 $dir",
      "if [ -e $policy ]; then",
      '  cat >/dev/null',
      "  echo 'policy kept as it is'",
      "  $note",
      'else',
      "  {$sudo}tee $policy >/dev/null",
      "  {$sudo}chmod 644 $policy",
      "  echo 'policy created'",
      'fi',
      "{$sudo}install -d -m 750 {$owner}$results",
    ]);
    return new Step("Create the policy at {$options['policy_file']} if it is missing, and the results directory", $script,
      fn() => $content, 'a minimal policy: ' . trim($content), 60.0);
  }

  private function buildStep(array $options, ResolvedConfig $config, string $asUser): Step {
    $profile = $config->get('profile');
    $root = $config->projectRoot;
    if ($profile === NULL || $root === NULL) {
      throw new ConfigException('Building the image needs a project: run this inside one, or set "profile" in phorward.json. '
        . 'Use --skip-build to install only the kit and the policy.');
    }
    $php = $config->get('php') ?? $this->catalog->defaultPhp($profile);
    if ($php === NULL) {
      throw new ConfigException("This client does not know the profile \"$profile\", so it cannot tell its default PHP version. "
        . 'Give --php, or use --skip-build and build the image on the server with server/build-image.');
    }
    $prefix = Quote::sh($options['prefix']);
    $build = "{$asUser}$prefix/server/build-image --profile " . Quote::sh($profile) . ' --php ' . Quote::sh($php) . ' --module "$T"';
    foreach ($config->get('dev_packages') ?? [] as $package) {
      $build .= ' --dev-package ' . Quote::sh($package);
    }
    $lines = [
      'set -e',
      "T=\$({$asUser}mktemp -d)",
      'trap \'rm -rf "$T"\' EXIT',
      "{$asUser}tar -xf - -C \"\$T\"",
      $build,
    ];
    if (!$options['skip_smoke']) {
      $lines[] = "{$asUser}$prefix/server/smoke-test --profile " . Quote::sh($profile) . ' --php ' . Quote::sh($php) . ' --module "$T"';
    }
    $excludes = (array) $config->get('sync.exclude');
    $vendor = (bool) $config->get('sync.include_vendor');
    return new Step("Build the $profile image for PHP $php as {$options['user']}" . ($options['skip_smoke'] ? '' : ' and smoke-test it'),
      implode("\n", $lines),
      fn() => $this->archiver->create($root, $excludes, $vendor),
      'the project (' . $root . ') as a tar archive, without .git, node_modules' . ($vendor ? '' : ', vendor') . ' or anything secret-looking',
      3600.0);
  }

  /**
   * @return string[]
   */
  private function notes(array $options, bool $buildable): array {
    $notes = [];
    if (!$buildable) {
      $notes[] = 'No image was built (--skip-build). Build one on the server with server/build-image, then run "phorward doctor".';
    }
    return $notes;
  }

  /**
   * The authorized_keys line and the sshd_config block for the restricted runtime key.
   *
   * @return string[]
   */
  public function accessLines(array $options): array {
    $run = $options['prefix'] . '/server/phorward-run';
    $env = $options['policy_file'] === self::DEFAULT_POLICY ? [] : ['PHORWARD_POLICY=' . $options['policy_file']];
    $command = implode(' ', array_merge($env === [] ? [] : array_merge(['env'], $env), [$run]));
    $lines = [];
    if ($options['public_key'] !== NULL) {
      $lines[] = 'authorized_keys line for the dedicated user (the key that runs phorward):';
      $lines[] = '  ' . AuthorizedKey::line($options['public_key'], $run, $options['from'], $env);
      $lines[] = '';
    }
    else {
      $lines[] = 'Pass --public-key FILE (the runtime client\'s .pub file) to get its authorized_keys line.';
      $lines[] = '';
    }
    $lines[] = 'sshd_config block that enforces the same limits (docs/ssh-access.md):';
    foreach ([
      "Match User {$options['user']}",
      '    AuthorizedKeysFile /etc/ssh/authorized_keys.d/%u',
      "    ForceCommand $command",
      '    PermitTTY no',
      '    DisableForwarding yes',
      '    PermitTunnel no',
      '    PermitUserRC no',
      '    PasswordAuthentication no',
    ] as $line) {
      $lines[] = '  ' . $line;
    }
    return $lines;
  }

}
