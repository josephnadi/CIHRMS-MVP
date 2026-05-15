<?php

namespace App\Enums;

enum ApplicantStatus: string
{
    case Applied    = 'applied';
    case Shortlisted = 'shortlisted';
    case Interviewed = 'interviewed';
    case Offered    = 'offered';
    case Hired      = 'hired';
    case Rejected   = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Applied     => 'Applied',
            self::Shortlisted => 'Shortlisted',
            self::Interviewed => 'Interviewed',
            self::Offered     => 'Offered',
            self::Hired       => 'Hired',
            self::Rejected    => 'Rejected',
        };
    }
}
