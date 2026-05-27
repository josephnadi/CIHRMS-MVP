<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spreadsheet helpers — most importantly, neutralising formula prefixes
 * that Excel / LibreOffice / Google Sheets interpret as live formulas.
 * A description like `=cmd|'/c calc'!A1` would launch Calculator when the
 * exported file is opened. Prefixing with a literal apostrophe forces the
 * cell to be treated as text. M15 audit fix.
 */
final class Spreadsheet
{
    /**
     * Escape a single cell value. Numbers and dates are passed through
     * (callers should keep them as native types — this is for strings).
     */
    public static function escapeCell(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }
}
