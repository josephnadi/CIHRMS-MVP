<?php

namespace App\Enums;

enum AttendanceSource: string
{
    case Biometric = 'biometric';   // Fingerprint / face / iris device
    case GpsMobile = 'gps_mobile';  // Mobile app clock-in with geofence
    case WebKiosk  = 'web_kiosk';   // Browser-based shared terminal
    case Manual    = 'manual';      // HR-entered (with audit trail + reason)
    case Webhook   = 'webhook';     // Generic 3rd-party feed

    public function label(): string
    {
        return match ($this) {
            self::Biometric => 'Biometric Device',
            self::GpsMobile => 'Mobile (GPS)',
            self::WebKiosk  => 'Web Kiosk',
            self::Manual    => 'Manual HR Entry',
            self::Webhook   => 'External Webhook',
        };
    }

    /** Manual entries always require a reason note for the audit trail. */
    public function requiresReason(): bool
    {
        return $this === self::Manual;
    }
}
