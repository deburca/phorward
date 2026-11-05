<?php

namespace Drupal\Tests\smoke_module\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the module installs.
 *
 * @group smoke_module
 */
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
