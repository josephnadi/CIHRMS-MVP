<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;
use SimpleXMLElement;
use Throwable;

class OfxStatementParser implements StatementParser
{
    public function parse(string $rawContent): array
    {
        $xml = preg_replace('/<\?OFX[^>]*\?>/', '', $rawContent);
        $xml = trim($xml);

        try {
            $doc = new SimpleXMLElement($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable $e) {
            throw new StatementParseException('ofx is not valid XML: ' . $e->getMessage(), 0, $e);
        }

        $stmt = $doc->BANKMSGSRSV1->STMTTRNRS->STMTRS ?? null;
        if ($stmt === null) {
            throw new StatementParseException('ofx missing BANKMSGSRSV1/STMTTRNRS/STMTRS node');
        }

        $currency = (string) ($stmt->CURDEF ?? 'GHS');

        $tranList = $stmt->BANKTRANLIST ?? null;
        if ($tranList === null) {
            throw new StatementParseException('ofx missing BANKTRANLIST node');
        }

        $periodStart   = $this->formatOfxDate((string) ($tranList->DTSTART ?? ''));
        $statementDate = $this->formatOfxDate((string) ($tranList->DTEND ?? ''));

        $closing = (float) ($stmt->LEDGERBAL->BALAMT ?? 0);
        $opening = (float) ($stmt->AVAILBAL->BALAMT ?? 0);

        $lines = [];
        foreach ($tranList->STMTTRN as $trn) {
            $lines[] = [
                'transaction_date' => $this->formatOfxDate((string) $trn->DTPOSTED),
                'value_date'       => $this->formatOfxDate((string) $trn->DTPOSTED),
                'description'      => trim((string) ($trn->NAME ?? $trn->MEMO ?? '')),
                'reference'        => trim((string) ($trn->FITID ?? '')) ?: null,
                'amount'           => round((float) $trn->TRNAMT, 2),
                'running_balance'  => null,
            ];
        }

        return [
            'period_start'    => $periodStart,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $currency,
            'lines'           => $lines,
        ];
    }

    private function formatOfxDate(string $raw): ?string
    {
        if ($raw === '') return null;
        $date = substr($raw, 0, 8);
        if (strlen($date) !== 8 || ! ctype_digit($date)) return null;
        return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    }
}
