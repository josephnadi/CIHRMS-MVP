<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\OfxStatementParser;

it('parses an OFX 2.x statement', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/sample.ofx'));
    $parser = new OfxStatementParser();

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect($result['period_start'])->toBe('2026-05-01');
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(2);

    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);
    expect($result['lines'][0]['reference'])->toBe('GCB-TX-001');
    expect($result['lines'][0]['description'])->toBe('SALARY ADV');

    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['reference'])->toBe('PST-001');
});

it('throws StatementParseException on malformed OFX', function () {
    $parser = new OfxStatementParser();
    expect(fn () => $parser->parse('not ofx'))->toThrow(StatementParseException::class);
});
