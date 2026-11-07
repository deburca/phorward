<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Cli\Application;
use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Install\InstallPlan;
use Phorward\Install\InstallPlanner;
use Phorward\Install\Step;
use Phorward\Ssh\Transport;
use Phorward\Ssh\TransportResult;

/**
 * "phorward server:install <admin-host>": installs or updates the server side.
 *
 * By default it only prints the plan and changes nothing. With --apply it runs the same steps over ssh, using
 * `sudo -n` (so it stops rather than asking for a password through a pipe). It never creates the dedicated user,
 * edits sshd_config or authorized_keys, or touches the Docker group: those stay with the operator, who is given
 * the exact lines to use. The login it uses is an administrator's, not the restricted runtime key.
 */
final class ServerInstallCommand {

  private const OWN_OPTIONS = ['apply', 'sudo', 'prefix', 'user', 'policy-file', 'results-dir', 'public-key', 'from',
    'skip-build', 'skip-smoke'];

  /**
   * @param \Closure(string, int): Transport $transportFactory
   */
  public function __construct(
    private readonly ConfigLoader $loader,
    private readonly \Closure $transportFactory,
    private readonly InstallPlanner $planner,
    private readonly Output $output,
  ) {}

  /**
   * @param string[] $positional
   * @param array<string, string|bool> $options
   * @param array<string, string> $env
   */
  public function run(array $positional, array $options, array $env, string $cwd): int {
    if (count($positional) !== 1) {
      throw new ConfigException('Usage: phorward server:install <admin-host> [--apply] [--public-key FILE] [options]. '
        . 'The admin host is an SSH login that can administer the server, not the restricted runtime key.');
    }
    $admin = $positional[0];
    if (!preg_match('/^[A-Za-z0-9._@][A-Za-z0-9._@-]{0,252}$/', $admin)) {
      throw new ConfigException('The admin host must be an SSH alias or user@host, not starting with "-".');
    }
    $own = [];
    foreach (self::OWN_OPTIONS as $name) {
      if (array_key_exists($name, $options)) {
        $own[$name] = $options[$name];
        unset($options[$name]);
      }
    }
    $apply = ($own['apply'] ?? FALSE) === TRUE;
    $installOptions = [
      'prefix' => $this->path($own, 'prefix', InstallPlanner::DEFAULT_PREFIX),
      'user' => $this->word($own, 'user', InstallPlanner::DEFAULT_USER),
      'policy_file' => $this->path($own, 'policy-file', InstallPlanner::DEFAULT_POLICY),
      'results_dir' => $this->path($own, 'results-dir', InstallPlanner::DEFAULT_RESULTS),
      'sudo' => ($own['sudo'] ?? TRUE) !== FALSE,
      'skip_build' => ($own['skip-build'] ?? FALSE) === TRUE,
      'skip_smoke' => ($own['skip-smoke'] ?? FALSE) === TRUE,
      'public_key' => isset($own['public-key']) ? $this->string($own, 'public-key') : NULL,
      'from' => isset($own['from']) ? $this->string($own, 'from') : NULL,
    ];
    $config = $this->loader->load($cwd, $env, $options);
    $plan = $this->planner->plan($installOptions, $config);
    $access = $this->planner->accessLines($installOptions);

    $this->printHeader($admin, $installOptions, $apply);
    if (!$apply) {
      $this->printPlan($admin, $plan);
      $this->printAfter($plan, $access);
      $this->output->line();
      $this->output->line('Nothing was changed. Run again with --apply to do this over ssh' . ($installOptions['sudo'] ? ' (it uses "sudo -n", so the login needs passwordless sudo)' : '') . '.');
      return Application::EXIT_OK;
    }
    $transport = ($this->transportFactory)($admin, (int) $config->get('connect_timeout'));
    $code = $this->apply($plan, $transport);
    if ($code === Application::EXIT_OK) {
      $this->printAfter($plan, $access);
      $this->output->line();
      $this->output->line('Done. Install the authorized_keys line and the sshd block above, then run "phorward doctor" from the client.');
    }
    return $code;
  }

  /**
   * @param array<string, string|bool> $own
   */
  private function string(array $own, string $name): string {
    $value = $own[$name];
    if (!is_string($value) || $value === '') {
      throw new ConfigException("--$name needs a value.");
    }
    return $value;
  }

  private function path(array $own, string $name, string $default): string {
    $value = isset($own[$name]) ? $this->string($own, $name) : $default;
    if (!preg_match('#^/[A-Za-z0-9_./-]{1,200}$#', $value) || in_array('..', explode('/', $value), TRUE) || str_ends_with($value, '/')) {
      throw new ConfigException("--$name must be an absolute path of letters, digits and _ . / -, without \"..\" or a trailing slash.");
    }
    return $value;
  }

  private function word(array $own, string $name, string $default): string {
    $value = isset($own[$name]) ? $this->string($own, $name) : $default;
    if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $value)) {
      throw new ConfigException("--$name must be a user name: lowercase letters, digits, _ and -.");
    }
    return $value;
  }

  private function printHeader(string $admin, array $o, bool $apply): void {
    $this->output->line(($apply ? 'Installing on ' : 'Plan for ') . $admin . ($apply ? '' : ' (nothing has been changed)'));
    $this->output->line(sprintf('  kit at %s, dedicated user %s, policy %s, results %s, %s', $o['prefix'], $o['user'],
      $o['policy_file'], $o['results_dir'], $o['sudo'] ? 'using sudo' : 'without sudo (the login is the dedicated user)'));
    $this->output->line();
  }

  private function printPlan(string $admin, InstallPlan $plan): void {
    $n = 0;
    foreach (array_merge([$plan->preflight], $plan->steps) as $step) {
      $n++;
      $this->output->line("$n. $step->title");
      $this->output->line("   ssh $admin sh -c '...' with this script:");
      foreach (explode("\n", $step->script) as $line) {
        $this->output->line('   | ' . $line);
      }
      if ($step->payload !== NULL) {
        $this->output->line('   stdin: ' . $step->payload);
      }
      $this->output->line();
    }
  }

  private function printAfter(InstallPlan $plan, array $access): void {
    foreach ($plan->notes as $note) {
      $this->output->line($note);
    }
    $this->output->line();
    foreach ($access as $line) {
      $this->output->line($line);
    }
  }

  private function apply(InstallPlan $plan, Transport $transport): int {
    $all = array_merge([$plan->preflight], $plan->steps);
    $total = count($all);
    foreach ($all as $i => $step) {
      $this->output->line(sprintf('[%d/%d] %s', $i + 1, $total, $step->title));
      $reply = $this->execute($transport, $step);
      if ($reply->timedOut || $reply->exitCode !== 0) {
        $this->output->line('      FAILED' . ($reply->timedOut ? ' (timed out)' : ' (exit ' . var_export($reply->exitCode, TRUE) . ')'));
        foreach ($this->tail($reply->stderr . "\n" . $reply->stdout, 12) as $line) {
          $this->output->line('      ' . $line);
        }
        $this->output->line($i === 0
          ? 'Nothing was changed. Fix the above and run again.'
          : 'Stopped here. Earlier steps are done and safe to repeat; fix the above and run again.');
        return Application::EXIT_INFRASTRUCTURE;
      }
      foreach ($this->tail($reply->stdout, 3) as $line) {
        $this->output->line('      ' . $line);
      }
    }
    return Application::EXIT_OK;
  }

  private function execute(Transport $transport, Step $step): TransportResult {
    return $transport->execute($step->remoteCommand(), $step->input !== NULL ? ($step->input)() : NULL, $step->timeout);
  }

  /**
   * @return string[]
   */
  private function tail(string $text, int $lines): array {
    $all = array_values(array_filter(array_map('rtrim', preg_split('/\R/', $text) ?: []), fn(string $l) => $l !== ''));
    return array_slice($all, -$lines);
  }

}
