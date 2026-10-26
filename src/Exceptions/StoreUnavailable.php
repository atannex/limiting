<?php

declare(strict_types=1);

namespace Atannex\Limiting\Exceptions;

use RuntimeException;

/** The store could not read or write a counter (connection lost, lock timeout, too much contention). */
final class StoreUnavailable extends RuntimeException {}
