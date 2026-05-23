<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class CsvStatementParser implements StatementParser
{
    private array $bankConfig;

    public function __construct(private readonly string $bankKey)
    {
        $configs = config('banks');
        if (! isset($configs[$bankKey])) {
            throw new StatementParseException("unknown bank key for CSV parser: {$bankKey}");
        }
        $this->bankConfig = $configs[$bankKey];
    }

    public function parse(string $rawContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        if (count($lines) < 4) {
            throw new StatementParseException('csv too short to be a valid bank statement');
        }

        $periodStart   = null;
        $statementDate = null;
        $opening       = null;
        $closing       = null;
        $headerIdx     = null;

        foreach ($lines as $idx => $row) {
            $cells = str_getcsv($row);
            $first = $cells[0] ?? '';

            if (str_contains($first, $this->bankConfig['period_row']) && count($cells) >= 3) {
                $periodStart   = $cells[1] ?? null;
                $statementDate = $cells[2] ?? null;
            } elseif (str_contains($first, $this->bankConfig['opening_row']) && count($cells) >= 2) {
                $opening = (float) $cells[1];
            } elseif (str_contains($first, $this->bankConfig['closing_row']) && count($cells) >= 2) {
                $closing = (float) $cells[1];
            } elseif ($first === $this->bankConfig['columns']['transaction_date']) {
                $headerIdx = $idx;
                break;
            }
        }

        if ($headerIdx === null || $statementDate === null || $opening === null || $closing === null) {
            throw new StatementParseException('csv missing required header rows (period / balances / column header)');
        }

        $headerCells = str_getcsv($lines[$headerIdx]);
        $colMap = array_flip($headerCells);

        $cols = $this->bankConfig['columns'];
        foreach (['transaction_date', 'description', 'debit', 'credit'] as $required) {
            if (! isset($colMap[$cols[$required]])) {
                throw new StatementParseException("csv missing required column: {$cols[$required]}");
            }
        }

        $resultLines = [];
        for ($i = $headerIdx + 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') continue;
            $cells = str_getcsv($lines[$i]);

            $debit  = (float) ($cells[$colMap[$cols['debit']]]  ?? 0);
            $credit = (float) ($cells[$colMap[$cols['credit']]] ?? 0);
            $signed = $credit - $debit;

            $resultLines[] = [
                'transaction_date' => $cells[$colMap[$cols['transaction_date']]] ?? null,
                'value_date'       => isset($colMap[$cols['value_date']])  ? ($cells[$colMap[$cols['value_date']]]  ?? null) : null,
                'description'      => $cells[$colMap[$cols['description']]] ?? '',
                'reference'        => isset($colMap[$cols['reference']])    ? ($cells[$colMap[$cols['reference']]]   ?: null) : null,
                'amount'           => round($signed, 2),
                'running_balance'  => isset($colMap[$cols['running_balance']]) ? ((float) ($cells[$colMap[$cols['running_balance']]] ?? 0)) : null,
            ];
        }

        return [
            'period_start'    => $periodStart,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $this->bankConfig['currency'],
            'lines'           => $resultLines,
        ];
    }
}
