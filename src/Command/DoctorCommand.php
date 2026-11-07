<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Archive\TarWriter;
use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Config\ResolvedConfig;
use Phorward\Project\ProfileCatalog;
use Phorward\Server\Protocol;
use Phorward\Ssh\Transport;
use Phorward\Ssh\TransportResult;

/**
 * "phorward doctor": checks the whole path from this machine to a working run, and for each problem says what to
 * do about it. It changes nothing on the server; the only thing it runs there is a one-line lint of a file it
 * sends itself. Exit status: 0 when nothing failed (warnings are fine), 1 when a check failed.
 */
final class DoctorCommand {

  private const MIN_DISK_FAIL = 1073741824;
  private const MIN_DISK_WARN = 5368709120;

  /**
   * @param \Closure(string, int): Transport $transportFactory Makes a transport for a host and connect timeout.
   */
  public function __construct(
    private readonly ConfigLoader $loader,
    private readonly \Closure $transportFactory,
    private readonly ProfileCatalog $catalog,
    private readonly Output $output,
  ) {}

  /**
   * @param string[] $positional
   * @param array<string, string|bool> $options
   * @param array<string, string> $env
   */
  public function run(array $positional, array $options, array $env, string $cwd): int {
    if ($positional !== []) {
      throw new ConfigException('"doctor" takes no arguments, only settings given as options.');
    }
    $format = 'text';
    if (isset($options['output'])) {
      $format = $options['output'];
      unset($options['output']);
      if (!in_array($format, ['text', 'json'], TRUE)) {
        throw new ConfigException('--output must be text or json.');
      }
    }
    $config = $this->loader->load($cwd, $env, $options);
    $checks = $this->diagnose($config);
    $failed = count(array_filter($checks, fn(Check $c) => $c->status === Check::FAIL));
    $warned = count(array_filter($checks, fn(Check $c) => $c->status === Check::WARN));

    if ($format === 'json') {
      $this->output->line(json_encode([
        'host' => $config->get('host'),
        'ok' => $failed === 0,
        'checks' => array_map(fn(Check $c) => ['name' => $c->name, 'status' => $c->status, 'detail' => $c->detail, 'fix' => $c->fix], $checks),
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    else {
      foreach ($checks as $check) {
        $label = ['ok' => 'ok   ', 'warn' => 'WARN ', 'fail' => 'FAIL '][$check->status];
        $this->output->line("$label $check->name: $check->detail");
        if ($check->fix !== NULL) {
          $this->output->line("       fix: $check->fix");
        }
      }
      $this->output->line();
      $this->output->line($failed === 0
        ? 'doctor: nothing is wrong' . ($warned ? " ($warned warning" . ($warned > 1 ? 's' : '') . ')' : '')
        : "doctor: $failed check" . ($failed > 1 ? 's' : '') . ' failed');
    }
    return $failed === 0 ? Application::EXIT_OK : Application::EXIT_FAILED;
  }

  /**
   * @return Check[]
   */
  private function diagnose(ResolvedConfig $config): array {
    $checks = [];
    $host = $config->get('host');
    if ($host === NULL) {
      return [Check::fail('configuration', 'no server is configured',
        'Set "host" to an SSH alias or user@host in ~/.config/phorward/config.json, or export PHORWARD_HOST. '
        . 'Run "phorward config" to see what is set.')];
    }
    $checks[] = Check::ok('configuration', "host $host, profile " . ($config->get('profile') ?? '(unknown)')
      . ', module ' . ($config->get('module') ?? '(unknown)'));

    $transport = ($this->transportFactory)($host, (int) $config->get('connect_timeout'));
    $timeout = (float) $config->get('connect_timeout') + 30;
    $reply = $transport->execute(Protocol::infoCommand(), NULL, $timeout);
    $info = $this->readInfo($reply, $checks, $host);
    if ($info === NULL) {
      return $checks;
    }

    $this->checkEngine($info, $checks);
    $image = $this->checkImage($config, $info, $checks);
    if ($image !== NULL) {
      $this->checkStale($config, $image, $checks);
      $this->checkEndToEnd($config, $image, $transport, $checks);
    }
    $this->checkPolicy($info, $checks);
    $this->checkDisk($info, $checks);
    return $checks;
  }

  /**
   * Interprets the reply to "info" as a series of checks. Returns the document when the server is usable.
   *
   * @param Check[] $checks
   * @return array<string, mixed>|null
   */
  private function readInfo(TransportResult $reply, array &$checks, string $host): ?array {
    if ($reply->timedOut) {
      $checks[] = Check::fail('ssh', "no answer from $host in time",
        'Check the network or VPN and any ProxyJump in ~/.ssh/config. Try "ssh ' . $host . '" by hand.');
      return NULL;
    }
    if ($reply->exitCode === 255) {
      $checks[] = Check::fail('ssh', "cannot connect to $host: " . $this->firstLine($reply->stderr),
        'Run "ssh -v ' . $host . '" to see why: the host name, the key, the host key, or the network. phorward uses '
        . 'your ~/.ssh/config as it is.');
      return NULL;
    }
    $checks[] = Check::ok('ssh', "connected to $host and the key was accepted");

    $info = Protocol::decode($reply->stdout);
    if ($info === NULL) {
      $missing = $reply->exitCode === 127 || stripos($reply->stderr, 'not found') !== FALSE;
      $checks[] = Check::fail('forced command',
        $missing ? 'the server has no phorward-run for this key' : 'the server did not answer with phorward-run output: ' . $this->firstLine($reply->stdout . $reply->stderr),
        $missing
          ? 'Install the server side with "phorward server:install", and make sure the key is the restricted one '
            . 'whose authorized_keys line runs phorward-run (docs/ssh-access.md).'
          : 'The key\'s forced command is not phorward-run, or something prints before it. See docs/ssh-access.md.');
      return NULL;
    }
    if (isset($info['error'])) {
      $code = (string) ($info['error']['code'] ?? 'unknown');
      if ($code === 'protocol_mismatch') {
        $server = (int) ($info['protocol'] ?? 0);
        $checks[] = Check::fail('protocol', "client speaks protocol " . Protocol::VERSION . ", server speaks $server",
          $server < Protocol::VERSION
            ? 'The server is older: update it with "phorward server:install".'
            : 'The server is newer: update this client with "composer update deburca/phorward".');
      }
      else {
        $checks[] = Check::fail('forced command', "the server refused the request: $code (" . (string) ($info['error']['message'] ?? '') . ')',
          $code === 'policy_invalid' ? 'Fix the server policy file (docs/policy.md).' : 'See docs/ssh-access.md and the server log.');
      }
      return NULL;
    }
    $checks[] = Check::ok('forced command', 'phorward-run answered, kit ' . (string) ($info['kit_version'] ?? '?'));
    if (($info['protocol'] ?? NULL) !== Protocol::VERSION) {
      $checks[] = Check::fail('protocol', 'client speaks protocol ' . Protocol::VERSION . ', server speaks ' . (string) ($info['protocol'] ?? '?'),
        'Update the older side so both come from the same release.');
      return NULL;
    }
    $checks[] = Check::ok('protocol', 'protocol ' . Protocol::VERSION . ' on both sides');
    return $info;
  }

  /**
   * @param array<string, mixed> $info
   * @param Check[] $checks
   */
  private function checkEngine(array $info, array &$checks): void {
    $engine = $info['engine'] ?? [];
    $name = (string) ($engine['name'] ?? 'unknown');
    $detail = "$name " . (string) ($engine['version'] ?? '?') . (($engine['rootless'] ?? FALSE) ? ', rootless' : ', not rootless');
    if (!($engine['rootless'] ?? FALSE)) {
      $checks[] = Check::warn('container engine', $detail,
        'The engine runs as root, so anything that escaped a container would be root on the host. Rootless Podman is '
        . 'recommended (docs/ssh-access.md).');
      return;
    }
    $checks[] = Check::ok('container engine', $detail);
  }

  /**
   * @param array<string, mixed> $info
   * @param Check[] $checks
   * @return array<string, mixed>|null The image to use.
   */
  private function checkImage(ResolvedConfig $config, array $info, array &$checks): ?array {
    $profile = $config->get('profile');
    $module = $config->get('module');
    $php = $config->get('php');
    if ($profile === NULL || $module === NULL) {
      $checks[] = Check::fail('image', 'the profile or module of this project is unknown',
        'Run this inside the project, or set "profile" and "module" in phorward.json ("phorward config" shows what '
        . 'was detected).');
      return NULL;
    }
    $images = array_values(array_filter($info['images'] ?? [], 'is_array'));
    $mine = array_values(array_filter($images, fn(array $i) => ($i['profile'] ?? NULL) === $profile
      && ($i['module_name'] ?? NULL) === $module && ($php === NULL || ($i['php'] ?? NULL) === $php)));
    if ($mine === []) {
      $have = array_map(fn(array $i) => (string) ($i['tag'] ?? '?'), $images);
      $checks[] = Check::fail('image', "no image for profile $profile, module $module" . ($php ? ", PHP $php" : '')
        . ($have ? '; the server has ' . implode(', ', array_slice($have, 0, 5)) : '; the server has none'),
        "Build it on the server: phorward server:install <admin-host> --profile $profile"
        . ($php ? " --php $php" : '') . ', or server/build-image there.');
      return NULL;
    }
    $image = $mine[0];
    $others = count($mine) > 1 ? ' (also PHP ' . implode(', ', array_map(fn($i) => (string) $i['php'], array_slice($mine, 1))) . ')' : '';
    $checks[] = Check::ok('image', (string) ($image['tag'] ?? '?') . ', built ' . (string) ($image['built'] ?? '?') . $others);
    return $image;
  }

  /**
   * @param array<string, mixed> $image
   * @param Check[] $checks
   */
  private function checkStale(ResolvedConfig $config, array $image, array &$checks): void {
    $theirs = (string) ($image['lock_sha256'] ?? '');
    $profile = (string) $config->get('profile');
    $file = $this->catalog->staleHashFile($profile);
    $mine = $config->projectRoot !== NULL ? $this->catalog->projectHash($profile, $config->projectRoot) : NULL;
    if ($file === NULL || $mine === NULL) {
      $checks[] = Check::ok('image freshness', 'not checked: this client cannot tell which file defines the image for this profile');
      return;
    }
    if ($theirs === '' || $theirs === 'unknown') {
      $checks[] = Check::warn('image freshness', 'the image does not record what it was built from',
        "Rebuild it once so $file can be compared in future.");
      return;
    }
    if ($theirs !== $mine) {
      $checks[] = Check::warn('image freshness', "$file has changed since the image was built",
        "Rebuild the image so runs use the project's current dependencies: phorward server:install <admin-host> --profile $profile.");
      return;
    }
    $checks[] = Check::ok('image freshness', "matches $file");
  }

  /**
   * Sends a one-file tree and runs lint on it, which proves the whole path and returns the image's tool versions.
   *
   * @param array<string, mixed> $image
   * @param Check[] $checks
   */
  private function checkEndToEnd(ResolvedConfig $config, array $image, Transport $transport, array &$checks): void {
    $php = (string) ($image['php'] ?? '');
    try {
      $command = Protocol::runCommand((string) $config->get('profile'), $php, (string) $config->get('module'), ['lint']);
    }
    catch (\InvalidArgumentException) {
      $checks[] = Check::fail('end to end', 'the server reported an image with a PHP version this client will not use: ' . json_encode($php),
        'Check the server with "phorward-run info --protocol 1" and the image tags there.');
      return;
    }
    $reply = $transport->execute($command, TarWriter::singleFile('phorward-doctor.php', "<?php\n"), (float) $config->get('connect_timeout') + 120);
    if ($reply->timedOut) {
      $checks[] = Check::fail('end to end', 'the test run did not finish in time',
        'The server may be overloaded or the policy timeout too low; try again, then see docs/policy.md.');
      return;
    }
    $result = Protocol::decode($reply->stdout);
    if ($result === NULL) {
      $checks[] = Check::fail('end to end', 'the test run gave no usable answer (exit ' . var_export($reply->exitCode, TRUE) . '): '
        . $this->firstLine($reply->stdout . $reply->stderr), 'See the server log, and run "ssh <host> phorward-run info --protocol 1" by hand.');
      return;
    }
    $verdict = (string) ($result['verdict'] ?? '?');
    if ($verdict === 'passed') {
      $checks[] = Check::ok('end to end', 'a test run passed in ' . (int) ($result['duration_ms'] ?? 0) . ' ms; tools: '
        . $this->tools((array) ($result['tool_versions'] ?? [])));
      return;
    }
    $code = (string) ($result['error']['code'] ?? '');
    $message = (string) ($result['error']['message'] ?? '');
    if ($code === 'busy') {
      $checks[] = Check::warn('end to end', 'the server is busy (all run slots are taken)',
        'Try again in a moment; a client run retries on its own. If it stays busy, raise "slots" in the policy (docs/policy.md).');
      return;
    }
    $fix = match ($code) {
      'profile_not_allowed' => 'Add the profile to "allowed_profiles" in the server policy (docs/policy.md).',
      'php_not_allowed' => 'Add the PHP version to "allowed_php" in the server policy (docs/policy.md).',
      'no_image' => 'The image disappeared between the two requests; build it again.',
      'ambiguous_image' => 'Several images match; set "module" in phorward.json.',
      'policy_invalid' => 'Fix the server policy file (docs/policy.md).',
      'bad_request', 'bad_archive', 'protocol_mismatch' => 'The client and the server disagree about the protocol; update both from one release.',
      default => 'See the message above and the server log.',
    };
    $checks[] = Check::fail('end to end', $verdict === 'failed' ? 'lint failed on a one-line file' : "the test run ended in $verdict"
      . ($code !== '' ? " ($code: $message)" : ''), $fix);
  }

  /**
   * @param array<string, mixed> $info
   * @param Check[] $checks
   */
  private function checkPolicy(array $info, array &$checks): void {
    $policy = $info['policy'] ?? [];
    $host = $info['host'] ?? [];
    $summary = sprintf('%s slot(s) of up to %s and %s CPU(s), %ss, on a host with %s CPU(s) and %s',
      (string) ($policy['slots'] ?? '?'), (string) ($policy['max_memory'] ?? '?'), (string) ($policy['max_cpus'] ?? '?'),
      (string) ($policy['max_timeout'] ?? '?'), (string) ($host['cpus'] ?? '?'), $this->gib((int) ($host['memory_bytes'] ?? 0)));
    $warnings = array_values(array_filter($info['warnings'] ?? [], 'is_array'));
    if ($warnings === []) {
      $checks[] = Check::ok('policy', $summary);
      return;
    }
    foreach ($warnings as $warning) {
      $checks[] = Check::warn('policy', (string) ($warning['message'] ?? $warning['code'] ?? 'the policy may be too generous'),
        'Adjust the server policy (docs/policy.md), then prove it with server/hostile-test. Policy: ' . $summary . '.');
    }
  }

  /**
   * @param array<string, mixed> $info
   * @param Check[] $checks
   */
  private function checkDisk(array $info, array &$checks): void {
    $free = $info['host']['disk_free_bytes'] ?? NULL;
    if (!is_int($free)) {
      $checks[] = Check::warn('free disk', 'the server does not report it', 'Update the server with "phorward server:install".');
      return;
    }
    if ($free < self::MIN_DISK_FAIL) {
      $checks[] = Check::fail('free disk', $this->gib($free) . ' free where results are kept',
        'Free some space: old images ("docker image prune" or "podman image prune") and old results.');
    }
    elseif ($free < self::MIN_DISK_WARN) {
      $checks[] = Check::warn('free disk', $this->gib($free) . ' free where results are kept',
        'An image needs about 3 GB to build; free some space before rebuilding.');
    }
    else {
      $checks[] = Check::ok('free disk', $this->gib($free) . ' free');
    }
  }

  private function firstLine(string $text): string {
    foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
      if (trim($line) !== '') {
        return mb_substr(trim($line), 0, 200);
      }
    }
    return '(no output)';
  }

  private function gib(int $bytes): string {
    return $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GiB' : round($bytes / 1048576) . ' MiB';
  }

  /**
   * @param array<string, mixed> $versions
   */
  private function tools(array $versions): string {
    $parts = [];
    foreach ($versions as $name => $version) {
      if (is_string($version) && preg_match('/^[A-Za-z0-9._-]+$/', (string) $name)) {
        $parts[] = "$name $version";
      }
    }
    return $parts === [] ? '(none reported)' : implode(', ', array_slice($parts, 0, 8));
  }

}
