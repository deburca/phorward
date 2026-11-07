<?php

declare(strict_types=1);

namespace Phorward\Tests;

/**
 * A scratch directory per test, removed afterwards.
 */
trait TempDirTrait {

  private string $tmp;

  protected function setUp(): void {
    parent::setUp();
    $this->tmp = realpath(sys_get_temp_dir()) . '/phorward-test-' . bin2hex(random_bytes(6));
    mkdir($this->tmp, 0777, TRUE);
  }

  protected function tearDown(): void {
    $this->remove($this->tmp);
    parent::tearDown();
  }

  protected function write(string $relative, string $content): string {
    $path = $this->tmp . '/' . $relative;
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, TRUE);
    }
    file_put_contents($path, $content);
    return $path;
  }

  protected function writeJson(string $relative, array $data): string {
    return $this->write($relative, json_encode($data, JSON_PRETTY_PRINT));
  }

  private function remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
          $this->remove($path . '/' . $entry);
        }
      }
      rmdir($path);
    }
    elseif (file_exists($path) || is_link($path)) {
      unlink($path);
    }
  }

}
