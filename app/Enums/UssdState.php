<?php

namespace App\Enums;

/**
 * Discrete states a USSD session can be in. Lives on `ussd_sessions.state`
 * and drives the next menu screen via UssdSessionHandler::render().
 */
enum UssdState: string
{
    case Welcome           = 'welcome';
    case AwaitingStaffId   = 'awaiting_staff_id';
    case AwaitingPin       = 'awaiting_pin';
    case Authenticated     = 'authenticated';
    case PayslipMenu       = 'payslip_menu';
    case LeaveBalance      = 'leave_balance';
    case ClockMenu         = 'clock_menu';
    case BankChangeCode    = 'bank_change_code';     // awaiting 6-digit confirmation code
    case BankChangeChoice  = 'bank_change_choice';   // confirm / reject after code is correct
    case WhistleblowerCode = 'whistleblower_code';
    case WhistleblowerStatus = 'whistleblower_status';
    case Terminated        = 'terminated';
    case PinLocked         = 'pin_locked';

    public function isAuthenticated(): bool
    {
        return ! in_array($this, [
            self::Welcome,
            self::AwaitingStaffId,
            self::AwaitingPin,
            self::PinLocked,
            self::Terminated,
            self::WhistleblowerCode,
            self::WhistleblowerStatus,
        ], true);
    }
}
