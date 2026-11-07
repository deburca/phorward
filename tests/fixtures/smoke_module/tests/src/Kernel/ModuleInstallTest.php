<?php

namespace Drupal\Tests\smoke_module\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the module installs.
 */
#[Group('smoke_module')]
#[RunTestsInSeparateProcesses]
class ModuleInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['smoke_module'];

  /**
   * The module is enabled in the kernel.
   */
  public function testModuleIsEnabled(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('smoke_module'));
  }

}
