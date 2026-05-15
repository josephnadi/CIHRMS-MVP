<?php

namespace App\Services\Whistleblower;

/**
 * Generates the human-friendly tracking code given to a whistleblower at
 * submission. The plaintext code is returned ONCE to the submitter and never
 * persisted — only the SHA-256 hash is stored. If the submitter loses the
 * code, the case becomes unreachable from their side (by design).
 *
 * Format: 4 groups of 4 base32 characters separated by dashes (12 chars total
 * before formatting). Crockford-style alphabet — no ambiguous I/L/O/U/0/1.
 */
class TrackingCodeGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const GROUPS   = 3;
    private const PER_GROUP = 4;

    public function generate(): string
    {
        $code = '';
        $alphabetLen = strlen(self::ALPHABET);

        for ($g = 0; $g < self::GROUPS; $g++) {
            if ($g > 0) $code .= '-';
            for ($i = 0; $i < self::PER_GROUP; $i++) {
                $code .= self::ALPHABET[random_int(0, $alphabetLen - 1)];
            }
        }

        return $code;
    }
}
