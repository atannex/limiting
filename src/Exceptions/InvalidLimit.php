<?php

declare(strict_types=1);

namespace Atannex\Limiting\Exceptions;

use InvalidArgumentException;

/** A limit, policy or middleware argument that can't work as written. */
final class InvalidLimit extends InvalidArgumentException {}
