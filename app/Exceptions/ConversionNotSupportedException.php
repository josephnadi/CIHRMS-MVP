<?php

namespace App\Exceptions;

use RuntimeException;

class ConversionNotSupportedException extends RuntimeException
{
    public static function forFormat(string $from, string $to): self
    {
        return new self("Conversion from {$from} to {$to} is not supported on this server.");
    }
}
