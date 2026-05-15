<?php

namespace App\Enums;

enum IdentityProviderKind: string
{
    case NiaOfficial   = 'nia_official';
    case ThirdPartyKyc = 'third_party_kyc';
    case ManualUpload  = 'manual_upload';

    public function label(): string
    {
        return match ($this) {
            self::NiaOfficial   => 'NIA Official Verification System',
            self::ThirdPartyKyc => 'Third-Party KYC Provider',
            self::ManualUpload  => 'Manual Ghana Card Upload',
        };
    }
}
