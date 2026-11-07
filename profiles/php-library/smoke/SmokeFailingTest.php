<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SmokeFailingTest extends TestCase
{
    public function testFails(): void
    {
        $this->assertSame(1, 2);
    }
}
