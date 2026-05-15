<?php

namespace App\Enums;

enum InvestigationActionType: string
{
    case Interview        = 'interview';
    case DocumentReview   = 'document_review';
    case SiteVisit        = 'site_visit';
    case EvidenceAdded    = 'evidence_added';
    case StatusChange     = 'status_change';
    case ReferralChraj    = 'referral_chraj';        // referred to Commission on Human Rights & Administrative Justice
    case ReferralAuditorGeneral = 'referral_auditor_general';
    case ReferralPolice   = 'referral_police';
    case FindingRecorded  = 'finding_recorded';
    case MessageSent      = 'message_sent';

    public function label(): string
    {
        return match ($this) {
            self::Interview              => 'Interview Conducted',
            self::DocumentReview          => 'Document Review',
            self::SiteVisit               => 'Site Visit',
            self::EvidenceAdded           => 'Evidence Added',
            self::StatusChange            => 'Status Changed',
            self::ReferralChraj           => 'Referred to CHRAJ',
            self::ReferralAuditorGeneral  => 'Referred to Auditor-General',
            self::ReferralPolice          => 'Referred to Police',
            self::FindingRecorded         => 'Finding Recorded',
            self::MessageSent             => 'Message Sent to Submitter',
        };
    }
}
