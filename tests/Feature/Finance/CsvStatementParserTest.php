<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\CsvStatementParser;

it('parses a GCB CSV statement', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/gcb-sample.csv'));
    $parser = new CsvStatementParser('gcb');

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect($result['period_start'])->toBe('2026-05-01');
    expect((float) $result['opening_balance'])->toBe(1000.00);
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(4);

    expect($result['lines'][0]['transaction_date'])->toBe('2026-05-05');
    expect($result['lines'][0]['description'])->toContain('SALARY ADV');
    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);

    expect($result['lines'][1]['transaction_date'])->toBe('2026-05-10');
    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['reference'])->toBe('PST-001');
});

it('throws StatementParseException on a non-CSV blob', function () {
    $parser = new CsvStatementParser('gcb');
    expect(fn () => $parser->parse('not a csv'))->toThrow(StatementParseException::class);
});

it('throws StatementParseException on unknown bank key', function () {
    expect(fn () => new CsvStatementParser('unknown-bank'))->toThrow(StatementParseException::class);
});
