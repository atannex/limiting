<?php

declare(strict_types=1);

namespace Atannex\Limiting\Events;

use Atannex\Limiting\Context;
use Atannex\Limiting\Decision;

/** A limit in shadow mode would have refused an attempt, and let it through. */
final class WouldLimit
{
    public function __construct(public readonly Decision $decision, public readonly Context $context) {}
}
