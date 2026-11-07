<?php

declare(strict_types=1);

namespace Sample\Tests;

use PHPUnit\Framework\TestCase;
use Sample\Greeter;

final class GreeterTest extends TestCase
{
    public function testGreet(): void
    {
        $this->assertSame('Hello, World!', (new Greeter())->greet('World'));
    }
}
