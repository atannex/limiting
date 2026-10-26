<?php

declare(strict_types=1);

namespace Atannex\Limiting\Console;

use Atannex\Limiting\Support\Duration;

/** Shared console formatting. */
trait Concerns
{
    protected function seconds(float $seconds): string
    {
        return $seconds <= 0 ? '-' : Duration::label(round($seconds, $seconds < 10 ? 1 : 0));
    }

    protected function at(float $timestamp): string
    {
        return date('Y-m-d H:i:s', (int) $timestamp);
    }
}
