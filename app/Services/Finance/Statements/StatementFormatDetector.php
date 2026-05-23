<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class StatementFormatDetector
{
    public function detect(string $fileName, string $rawContent): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return 'csv';
        }
        if ($ext === 'ofx') {
            return 'ofx';
        }
        if (in_array($ext, ['sta', 'mt940', 'mt'], true)) {
            return 'mt940';
        }

        $head = ltrim(substr($rawContent, 0, 200));
        if (str_starts_with($head, 'OFXHEADER') || str_starts_with($head, '<OFX>')) {
            return 'ofx';
        }
        if (str_starts_with($head, ':20:') || preg_match('/^:\d{2}[A-Z]?:/m', $head)) {
            return 'mt940';
        }

        throw new StatementParseException(
            "could not detect statement format for '{$fileName}'. supported: csv, ofx, mt940."
        );
    }
}
