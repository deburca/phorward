<?php

declare(strict_types=1);

namespace Sample;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}
