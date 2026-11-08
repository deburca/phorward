<?php

declare(strict_types=1);

namespace Phorward\Harness;

use Phorward\Config\ConfigException;
use Phorward\Project\ProfileCatalog;

/**
 * Copies the harness templates into a project, with the project's facts filled in, and tells apart the files it
 * wrote from the files a person has since edited. It records the hash of everything it writes in
 * .harness/installed.json; a file whose content no longer matches its record is the person's, and is left alone
 * unless the caller forces it.
 */
final class TemplateInstaller {

  public const CREATED = 'created';
  public const UPDATED = 'updated';
  public const UNCHANGED = 'unchanged';
  public const KEPT = 'kept';

  private const RECORD = '.harness/installed.json';

  /** Template file (below templates/) to the project path it is installed at; the starter conventions come from the profile. */
  private const FILES = [
    'opencode/agent/phorward-dev.md' => '.opencode/agent/phorward-dev.md',
    'opencode/agent/phorward-tests.md' => '.opencode/agent/phorward-tests.md',
    'opencode/command/feature.md' => '.opencode/command/feature.md',
    'opencode/command/fix-bug.md' => '.opencode/command/fix-bug.md',
    'opencode/command/verify.md' => '.opencode/command/verify.md',
    'harness/harness.sh' => '.harness/harness.sh',
  ];

  private const CONVENTIONS = '.harness/CONVENTIONS.md';

  /** The command harness.sh runs when HARNESS_PHORWARD is not set. */
  private const COMMAND_FILE = '.harness/command';

  private const EXECUTABLE = ['.harness/harness.sh'];

  public function __construct(private readonly ProfileCatalog $catalog, private readonly string $templatesDir) {}

  /**
   * @return array<string, string> Project path to one of the status constants, in the order installed.
   */
  public function install(string $root, string $profile, string $project, bool $force, bool $dryRun, string $command = 'vendor/bin/phorward'): array {
    $spec = $this->catalog->harnessSpec($profile);
    if ($spec === NULL) {
      throw new ConfigException("This package has no harness templates for the profile \"$profile\".");
    }
    $facts = ['{{project}}' => $project, '{{kind}}' => $spec['kind'], '{{phorward}}' => $command];
    $sources = [];
    foreach (self::FILES as $template => $target) {
      $sources[$target] = $this->templatesDir . '/' . $template;
    }
    $sources[self::CONVENTIONS] = $spec['conventions'];
    $generated = [self::COMMAND_FILE => $command . "\n"];

    $record = $this->readRecord($root);
    $results = [];
    $contents = [];
    foreach ($sources as $target => $source) {
      $text = file_get_contents($source);
      if ($text === FALSE) {
        throw new ConfigException("Cannot read the template $source.");
      }
      $contents[$target] = strtr($text, $facts);
    }
    $contents += $generated;
    foreach ($contents as $target => $content) {
      $hash = hash('sha256', $content);
      $path = $root . '/' . $target;
      $existing = is_file($path) ? (string) file_get_contents($path) : NULL;
      if ($existing === NULL) {
        $status = self::CREATED;
      }
      elseif ($existing === $content) {
        $record[$target] = $hash;
        $results[$target] = self::UNCHANGED;
        continue;
      }
      elseif ($force || ($record[$target] ?? NULL) === hash('sha256', $existing)) {
        $status = self::UPDATED;
      }
      else {
        $results[$target] = self::KEPT;
        continue;
      }
      if (!$dryRun) {
        $this->write($path, $content, in_array($target, self::EXECUTABLE, TRUE));
        $record[$target] = $hash;
      }
      $results[$target] = $status;
    }
    if (!$dryRun) {
      ksort($record);
      $this->write($root . '/' . self::RECORD, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", FALSE);
    }
    return $results;
  }

  /**
   * @return array<string, string>
   */
  private function readRecord(string $root): array {
    $path = $root . '/' . self::RECORD;
    $data = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : NULL;
    return is_array($data) ? array_filter($data, 'is_string') : [];
  }

  private function write(string $path, string $content, bool $executable): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, TRUE) && !is_dir($directory)) {
      throw new ConfigException("Cannot create $directory.");
    }
    if (file_put_contents($path, $content) === FALSE) {
      throw new ConfigException("Cannot write $path.");
    }
    chmod($path, $executable ? 0755 : 0644);
  }

}
