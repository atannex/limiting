<?php

declare(strict_types=1);

namespace Atannex\Limiting\Events;

use Atannex\Limiting\Context;
use Atannex\Limiting\Decision;

/** A limit refused an attempt. */
final class Limited
{
    public function __construct(public readonly Decision $decision, public readonly Context $context) {}
}
