<?php

declare(strict_types=1);

namespace App\Services\Finance\Statements;

use App\Exceptions\Finance\StatementParseException;

class Mt940StatementParser implements StatementParser
{
    public function parse(string $rawContent): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $rawContent);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($l) => $l !== '' && $l !== '-'));

        if (! preg_grep('/^:\d{2}[A-Z]?:/', $lines)) {
            throw new StatementParseException('mt940 has no recognized tag lines');
        }

        $opening = null;
        $closing = null;
        $currency = 'GHS';
        $statementDate = null;
        $tranLines = [];

        $i = 0;
        while ($i < count($lines)) {
            $line = $lines[$i];

            if (preg_match('/^:60[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)/', $line, $m)) {
                $sign = $m[1] === 'C' ? 1 : -1;
                $opening = $sign * $this->mtAmount($m[4]);
                $currency = $m[3];
            } elseif (preg_match('/^:62[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)/', $line, $m)) {
                $sign = $m[1] === 'C' ? 1 : -1;
                $closing = $sign * $this->mtAmount($m[4]);
                $statementDate = $this->mtDate($m[2]);
                $currency = $m[3];
            } elseif (preg_match('/^:61:(\d{6})(\d{4})?([CD])R?([\d,\.]+)([A-Z0-9]{4})([^\/]*)\/?\/?(.*)$/', $line, $m)) {
                $txDate = $this->mtDate($m[1]);
                $valueDate = $m[2] !== '' ? $this->mtDateValue($m[1], $m[2]) : $txDate;
                $sign = $m[3] === 'C' ? 1 : -1;
                $amount = round($sign * $this->mtAmount($m[4]), 2);
                $reference = trim($m[6]) ?: null;
                $description = '';

                if (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], ':86:')) {
                    $description = trim(substr($lines[$i + 1], 4));
                    $i++;
                }

                $tranLines[] = [
                    'transaction_date' => $txDate,
                    'value_date'       => $valueDate,
                    'description'      => $description,
                    'reference'        => $reference,
                    'amount'           => $amount,
                    'running_balance'  => null,
                ];
            }
            $i++;
        }

        if ($opening === null || $closing === null || $statementDate === null) {
            throw new StatementParseException('mt940 missing :60F: opening, :62F: closing, or statement date');
        }

        return [
            'period_start'    => null,
            'statement_date'  => $statementDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'currency'        => $currency,
            'lines'           => $tranLines,
        ];
    }

    private function mtAmount(string $raw): float
    {
        return (float) str_replace(',', '.', $raw);
    }

    private function mtDate(string $yymmdd): string
    {
        $yy = (int) substr($yymmdd, 0, 2);
        $century = $yy >= 80 ? 1900 : 2000;
        return ($century + $yy) . '-' . substr($yymmdd, 2, 2) . '-' . substr($yymmdd, 4, 2);
    }

    private function mtDateValue(string $txDateYymmdd, string $valueMmdd): string
    {
        $yy = (int) substr($txDateYymmdd, 0, 2);
        $century = $yy >= 80 ? 1900 : 2000;
        return ($century + $yy) . '-' . substr($valueMmdd, 0, 2) . '-' . substr($valueMmdd, 2, 2);
    }
}
