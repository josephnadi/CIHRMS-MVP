<?php

declare(strict_types=1);

use App\Exceptions\Finance\AlreadyPostedException;
use App\Exceptions\Finance\MissingAccountMappingException;

it('exposes domain-specific posting exceptions', function () {
    expect(new MissingAccountMappingException('x'))->toBeInstanceOf(DomainException::class)
        ->and(new AlreadyPostedException('y'))->toBeInstanceOf(DomainException::class);
});
