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

    /** Largest bank statement we'll accept (bytes). M12 DoS cap. */
    private const MAX_BYTES = 50 * 1024 * 1024;       // 50 MB
    /** Max number of CSV/MT940/OFX lines we'll parse. M12 DoS cap. */
    private const MAX_LINES = 100_000;

    public function import(
        UploadedFile $file,
        OrgBankAccount $bank,
        User $importer,
        ?string $bankKey = null,
    ): BankStatement {
        // M12: pre-flight bounds. file->getSize() is from the metadata of the
        // uploaded part; a hostile client could spoof it, so we re-measure
        // the actual bytes after reading.
        if ($file->getSize() !== false && $file->getSize() > self::MAX_BYTES) {
            throw new DomainException('statement file exceeds 50 MB cap');
        }
        $raw = file_get_contents($file->getRealPath()) ?: '';
        if ($raw === '') {
            throw new DomainException('uploaded file is empty');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new DomainException('statement file exceeds 50 MB cap');
        }
        if (substr_count($raw, "\n") > self::MAX_LINES) {
            throw new DomainException('statement file exceeds ' . self::MAX_LINES . ' line cap');
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
