<?php

namespace App\Services\Credits;

use App\Support\Money;
use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly Money $required, public readonly Money $available)
    {
        parent::__construct('Not enough credits.');
    }
}
