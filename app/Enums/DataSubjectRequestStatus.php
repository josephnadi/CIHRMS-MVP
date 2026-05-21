<?php

namespace App\Enums;

enum DataSubjectRequestStatus: string
{
    case PendingVerification = 'pending_verification'; // public submission, awaiting email-token click
    case Submitted          = 'submitted';
    case Acknowledged       = 'acknowledged';      // DPO has assigned + started work
    case InReview           = 'in_review';
    case PendingSubject     = 'pending_subject';   // waiting on subject to confirm something
    case Fulfilled          = 'fulfilled';
    case PartiallyFulfilled = 'partially_fulfilled'; // some elements held back per statute
    case Rejected           = 'rejected';
    case Withdrawn          = 'withdrawn';
    case Overdue            = 'overdue';            // past 30-day SLA without resolution

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Pending Email Verification',
            self::Submitted          => 'Submitted',
            self::Acknowledged       => 'Acknowledged',
            self::InReview           => 'In Review',
            self::PendingSubject     => 'Pending Subject',
            self::Fulfilled          => 'Fulfilled',
            self::PartiallyFulfilled => 'Partially Fulfilled',
            self::Rejected           => 'Rejected',
            self::Withdrawn          => 'Withdrawn',
            self::Overdue            => 'Overdue',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Fulfilled, self::PartiallyFulfilled, self::Rejected, self::Withdrawn,
        ], true);
    }
}
