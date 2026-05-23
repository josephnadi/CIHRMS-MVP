<?php

declare(strict_types=1);

use App\Exceptions\Finance\StatementParseException;
use App\Services\Finance\Statements\Mt940StatementParser;

it('parses an MT940 statement with debit and credit lines', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/Finance/Statements/sample.mt940'));
    $parser = new Mt940StatementParser();

    $result = $parser->parse($raw);

    expect($result['statement_date'])->toBe('2026-05-31');
    expect((float) $result['opening_balance'])->toBe(1000.00);
    expect((float) $result['closing_balance'])->toBe(2150.50);
    expect($result['currency'])->toBe('GHS');
    expect($result['lines'])->toHaveCount(2);

    expect((float) $result['lines'][0]['amount'])->toBe(-500.00);
    expect($result['lines'][0]['transaction_date'])->toBe('2026-05-05');
    expect($result['lines'][0]['description'])->toBe('SALARY ADV TO J NADI');

    expect((float) $result['lines'][1]['amount'])->toBe(1500.00);
    expect($result['lines'][1]['transaction_date'])->toBe('2026-05-10');
});

it('throws StatementParseException on garbage input', function () {
    $parser = new Mt940StatementParser();
    expect(fn () => $parser->parse('totally not mt940'))->toThrow(StatementParseException::class);
});
