<?php

namespace App\Enums;

/**
 * The six data-subject rights recognised under Ghana's Data Protection Act
 * 2012 (Act 843), §§17–22. Each enum case maps to a specific lifecycle in
 * DataSubjectRequestService.
 */
enum DataSubjectRequestType: string
{
    case Access        = 'access';         // §17 — copy of all data held about subject
    case Rectification = 'rectification';  // §18 — correct inaccurate data
    case Erasure       = 'erasure';        // §19 — delete data (with statutory holds)
    case Portability   = 'portability';    // §21 — machine-readable export
    case Objection     = 'objection';      // §20 — stop processing for a purpose
    case Information   = 'information';    // §22 — what is collected & why

    public function label(): string
    {
        return match ($this) {
            self::Access        => 'Right to Access',
            self::Rectification => 'Right to Rectification',
            self::Erasure       => 'Right to Erasure',
            self::Portability   => 'Right to Data Portability',
            self::Objection     => 'Right to Object to Processing',
            self::Information   => 'Right to be Informed',
        };
    }

    /** Whether this request type produces a downloadable export bundle. */
    public function producesExport(): bool
    {
        return in_array($this, [self::Access, self::Portability, self::Information], true);
    }

    /** Whether this request mutates data (and so needs higher-grade approval). */
    public function isMutating(): bool
    {
        return in_array($this, [self::Rectification, self::Erasure, self::Objection], true);
    }
}
