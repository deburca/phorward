<?php

declare(strict_types=1);

namespace Phorward\Install;

use Phorward\Config\ConfigException;
use Symfony\Component\Process\Process;

/**
 * Builds the authorized_keys line that lets one client key run phorward-run and nothing else. The same rules as
 * server/authorized-key (which a test compares this against): a plain public key of a strong type, and values
 * that cannot break out of the quoting.
 */
final class AuthorizedKey {

  private const KEY_TYPES = ['ssh-ed25519', 'sk-ssh-ed25519@openssh.com', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384',
    'ecdsa-sha2-nistp521', 'sk-ecdsa-sha2-nistp256@openssh.com', 'ssh-rsa'];
  private const MIN_RSA_BITS = 3072;

  /**
   * @param string[] $env NAME=VALUE pairs for the forced command.
   */
  public static function line(string $publicKeyFile, string $runPath, ?string $from = NULL, array $env = []): string {
    if (!preg_match('#^/[A-Za-z0-9_./-]+$#', $runPath) || in_array('..', explode('/', $runPath), TRUE)) {
      throw new ConfigException('The path of phorward-run must be absolute and made of letters, digits and _ . / -.');
    }
    foreach ($env as $item) {
      if (!preg_match('/^[A-Z][A-Z0-9_]*=[A-Za-z0-9_.\/:@+-]*$/', $item)) {
        throw new ConfigException("Environment setting \"$item\" must be NAME=VALUE with a plain value.");
      }
    }
    if ($from !== NULL && !preg_match('/^[A-Za-z0-9.*?:\/!_-]+(,[A-Za-z0-9.*?:\/!_-]+)*$/', $from)) {
      throw new ConfigException('The "from" pattern takes comma-separated addresses or patterns, e.g. 192.0.2.0/24.');
    }
    [$type, $body, $comment] = self::readKey($publicKeyFile);
    $command = implode(' ', array_merge($env === [] ? [] : array_merge(['env'], $env), [$runPath]));
    $options = ['restrict', "command=\"$command\""];
    if ($from !== NULL && $from !== '') {
      $options[] = "from=\"$from\"";
    }
    return implode(',', $options) . " $type $body" . ($comment !== '' ? " $comment" : '');
  }

  /**
   * @return array{0: string, 1: string, 2: string}
   */
  private static function readKey(string $path): array {
    $text = @file_get_contents($path);
    if ($text === FALSE) {
      throw new ConfigException("Cannot read the public key $path.");
    }
    if (str_contains($text, 'PRIVATE KEY')) {
      throw new ConfigException("$path holds a private key; give the .pub file. Private keys never go on the server.");
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []),
      fn(string $l) => $l !== '' && !str_starts_with($l, '#')));
    if (count($lines) !== 1) {
      throw new ConfigException("$path must hold exactly one public key (found " . count($lines) . ').');
    }
    $parts = preg_split('/\s+/', $lines[0], 3) ?: [];
    if (count($parts) < 2 || !in_array($parts[0], self::KEY_TYPES, TRUE)) {
      throw new ConfigException("$path does not start with a public key type; a key that already carries options is "
        . 'refused, so nobody can smuggle options in.');
    }
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $parts[1])) {
      throw new ConfigException('The key body is not base64.');
    }
    $comment = isset($parts[2]) ? trim($parts[2]) : '';
    if (!preg_match('/^[A-Za-z0-9@._ -]*$/', $comment)) {
      throw new ConfigException('The key comment has characters outside letters, digits and @ . _ - space.');
    }
    $check = new Process(['ssh-keygen', '-l', '-f', $path]);
    try {
      $check->run();
      // 127 is what a shell reports for a command it cannot find.
      $ran = $check->getExitCode() !== 127;
    }
    catch (\Throwable) {
      $ran = FALSE;
    }
    if (!$ran && $parts[0] === 'ssh-rsa') {
      throw new ConfigException('ssh-keygen is not available, so the strength of an RSA key cannot be checked; use an ed25519 key.');
    }
    if ($ran && !$check->isSuccessful()) {
      throw new ConfigException("ssh-keygen does not accept $path as a public key: " . trim($check->getErrorOutput()));
    }
    if ($ran && $parts[0] === 'ssh-rsa' && preg_match('/^(\d+)/', $check->getOutput(), $m) && (int) $m[1] < self::MIN_RSA_BITS) {
      throw new ConfigException("RSA key of {$m[1]} bits; use ed25519 or RSA of at least " . self::MIN_RSA_BITS . ' bits.');
    }
    return [$parts[0], $parts[1], $comment];
  }

}
