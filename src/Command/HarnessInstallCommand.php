<?php

declare(strict_types=1);

namespace Phorward\Command;

use Phorward\Cli\Output;
use Phorward\Config\ConfigException;
use Phorward\Config\ConfigLoader;
use Phorward\Harness\TemplateInstaller;

/**
 * "phorward harness:install": puts the opencode agents and commands, the retry loop and a starter conventions file
 * into the project, with its name and kind filled in. Never overwrites a file a person has edited unless --force.
 * Exit status: 0 done (kept files are reported, not an error); 2 a usage or setup error.
 */
final class HarnessInstallCommand {

  public function __construct(
    private readonly ConfigLoader $loader,
    private readonly TemplateInstaller $installer,
    private readonly Output $output,
  ) {}

  /**
   * @param string[] $positional
   * @param array<string, string|bool> $options
   * @param array<string, string> $env
   */
  public function run(array $positional, array $options, array $env, string $cwd): int {
    if ($positional !== []) {
      throw new ConfigException('"harness:install" takes no arguments. Use --force to overwrite edited files, --dry-run to see what would change.');
    }
    $force = ($options['force'] ?? FALSE) === TRUE;
    $dryRun = ($options['dry-run'] ?? FALSE) === TRUE;
    unset($options['force'], $options['dry-run']);
    $config = $this->loader->load($cwd, $env, $options);
    $root = $config->projectRoot;
    $profile = $config->get('profile');
    $project = $config->get('module');
    if ($root === NULL || $profile === NULL || $project === NULL) {
      throw new ConfigException('Cannot tell which project this is. Run it inside the project, or set "profile" and '
        . '"module" in phorward.json ("phorward config" shows what was detected).');
    }

    $results = $this->installer->install($root, (string) $profile, (string) $project, $force, $dryRun, (string) $config->get('harness.command'));
    $labels = [
      TemplateInstaller::CREATED => $dryRun ? 'would create' : 'created',
      TemplateInstaller::UPDATED => $dryRun ? 'would update' : 'updated',
      TemplateInstaller::UNCHANGED => 'unchanged',
      TemplateInstaller::KEPT => 'kept (edited)',
    ];
    foreach ($results as $path => $status) {
      $this->output->line(sprintf('  %-14s %s', $labels[$status], $path));
    }
    $this->output->line();
    if (in_array(TemplateInstaller::KEPT, $results, TRUE)) {
      $this->output->line('Files marked "kept" differ from what was installed, so they were left alone. Run again with --force to');
      $this->output->line('replace them with the current templates.');
      $this->output->line();
    }
    $this->output->line('Next: edit .harness/CONVENTIONS.md (the agents read it first) and commit .opencode/ and .harness/. Then run');
    $this->output->line('.harness/harness.sh --model provider/model "task". Remember what the gates do and do not prove:');
    $this->output->line('they filter lint, coding-standard and CI failures; a person still reviews the logic and the tests.');
    return 0;
  }

}
