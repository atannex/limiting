<?php

declare(strict_types=1);

namespace Atannex\Limiting\Exceptions;

use Atannex\Limiting\Verdict;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A request from an address or user on the deny list. */
final class AccessDenied extends HttpException
{
    public function __construct(public readonly Verdict $verdict, int $status = 403)
    {
        parent::__construct($status, 'Access denied.');
    }
}
