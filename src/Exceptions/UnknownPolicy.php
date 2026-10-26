<?php

declare(strict_types=1);

namespace Atannex\Limiting\Exceptions;

use InvalidArgumentException;

final class UnknownPolicy extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self("No rate limiting policy is named \"{$name}\". Define it in config/limiting.php or with Limiting::define().");
    }
}
