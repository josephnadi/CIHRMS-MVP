<?php

namespace App\Enums;

enum WhistleblowerStatus: string
{
    case Submitted             = 'submitted';
    case Triaged               = 'triaged';
    case Investigating         = 'investigating';
    case EvidenceGathering     = 'evidence_gathering';
    case ClosedSubstantiated   = 'closed_substantiated';     // claim found valid
    case ClosedUnsubstantiated = 'closed_unsubstantiated';   // claim not supported by evidence
    case ClosedReferred        = 'closed_referred';           // referred out (CHRAJ, Audit, Police)
    case Withdrawn             = 'withdrawn';                 // submitter withdrew the report

    public function label(): string
    {
        return match ($this) {
            self::Submitted             => 'Submitted',
            self::Triaged               => 'Triaged',
            self::Investigating         => 'Investigating',
            self::EvidenceGathering     => 'Evidence Gathering',
            self::ClosedSubstantiated   => 'Closed — Substantiated',
            self::ClosedUnsubstantiated => 'Closed — Unsubstantiated',
            self::ClosedReferred        => 'Closed — Referred',
            self::Withdrawn             => 'Withdrawn',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [
            self::ClosedSubstantiated,
            self::ClosedUnsubstantiated,
            self::ClosedReferred,
            self::Withdrawn,
        ], true);
    }
}
