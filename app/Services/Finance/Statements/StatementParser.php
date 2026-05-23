<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

interface StatementParser
{
    public function parse(string $rawContent): array;
}
