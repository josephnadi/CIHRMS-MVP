<?php

use App\Support\Spreadsheet;

it('escapes leading = / + / - / @ to prevent CSV formula injection', function () {
    expect(Spreadsheet::escapeCell('=cmd|\'/c calc\'!A1'))->toStartWith("'");
    expect(Spreadsheet::escapeCell('+SUM(A:A)'))->toStartWith("'");
    expect(Spreadsheet::escapeCell('-1+1'))->toStartWith("'");
    expect(Spreadsheet::escapeCell('@HYPERLINK(...)'))->toStartWith("'");
});

it('leaves benign strings untouched', function () {
    expect(Spreadsheet::escapeCell('Joseph Nadi'))->toBe('Joseph Nadi');
    expect(Spreadsheet::escapeCell('payroll for May'))->toBe('payroll for May');
});

it('passes nulls and numerics through unchanged', function () {
    expect(Spreadsheet::escapeCell(null))->toBeNull();
    expect(Spreadsheet::escapeCell(42))->toBe(42);
    expect(Spreadsheet::escapeCell(3.14))->toBe(3.14);
});
