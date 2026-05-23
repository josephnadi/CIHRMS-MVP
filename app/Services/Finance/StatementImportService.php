<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\Finance\StatementParseException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\OrgBankAccount;
use App\Models\User;
use App\Services\Finance\Statements\CsvStatementParser;
use App\Services\Finance\Statements\Mt940StatementParser;
use App\Services\Finance\Statements\OfxStatementParser;
use App\Services\Finance\Statements\StatementFormatDetector;
use App\Services\Finance\Statements\StatementParser;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StatementImportService
{
    public function __construct(private readonly StatementFormatDetector $detector)
    {
    }

    public function import(
        UploadedFile $file,
        OrgBankAccount $bank,
        User $importer,
        ?string $bankKey = null,
    ): BankStatement {
        $raw = file_get_contents($file->getRealPath()) ?: '';
        if ($raw === '') {
            throw new DomainException('uploaded file is empty');
        }

        $fileHash = hash('sha256', $raw);

        $existing = BankStatement::where('file_hash', $fileHash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $format = $this->detector->detect($file->getClientOriginalName(), $raw);
        $parser = $this->parserFor($format, $bankKey);
        $parsed = $parser->parse($raw);

        if ($parsed['currency'] !== ($bank->currency ?? 'GHS')) {
            throw new DomainException(sprintf(
                'currency mismatch: file is %s but bank account is %s',
                $parsed['currency'], $bank->currency ?? 'GHS',
            ));
        }

        return DB::transaction(function () use ($file, $fileHash, $format, $parsed, $bank, $importer) {
            $stmt = BankStatement::create([
                'org_bank_account_id' => $bank->id,
                'statement_date'      => $parsed['statement_date'],
                'period_start'        => $parsed['period_start'],
                'opening_balance'     => $parsed['opening_balance'],
                'closing_balance'     => $parsed['closing_balance'],
                'currency'            => $parsed['currency'],
                'file_hash'           => $fileHash,
                'file_name'           => $file->getClientOriginalName(),
                'format'              => $format,
                'imported_by'         => $importer->id,
            ]);

            $lineNo = 0;
            foreach ($parsed['lines'] as $line) {
                $lineNo++;
                $lineHash = hash('sha256', sprintf('%s|%.2f|%s|%s',
                    $line['transaction_date'] ?? '',
                    (float) ($line['amount'] ?? 0),
                    $line['description'] ?? '',
                    $line['reference'] ?? '',
                ));

                BankStatementLine::create([
                    'bank_statement_id' => $stmt->id,
                    'line_no'           => $lineNo,
                    'transaction_date'  => $line['transaction_date'],
                    'value_date'        => $line['value_date'] ?? null,
                    'description'       => $line['description'] ?? '',
                    'reference'         => $line['reference'] ?? null,
                    'amount'            => $line['amount'] ?? 0,
                    'running_balance'   => $line['running_balance'] ?? null,
                    'line_hash'         => $lineHash,
                ]);
            }

            return $stmt->fresh();
        });
    }

    private function parserFor(string $format, ?string $bankKey): StatementParser
    {
        return match ($format) {
            'csv'   => new CsvStatementParser($bankKey ?? 'gcb'),
            'ofx'   => new OfxStatementParser(),
            'mt940' => new Mt940StatementParser(),
            default => throw new StatementParseException("unknown statement format: {$format}"),
        };
    }
}
