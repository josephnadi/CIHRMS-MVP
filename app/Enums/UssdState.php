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

    // ── M3: CIHRM member fee flows (parallel to staff self-service) ──
    case MemberAwaitingPin   = 'member_awaiting_pin';   // member identified by msisdn, asking for PIN
    case MemberMainMenu      = 'member_main_menu';      // 1=fees 2=pay 3=last receipt 4=exit
    case MemberFeeSelect     = 'member_fee_select';     // listing payable invoices
    case MemberFeeConfirm    = 'member_fee_confirm';    // confirm before mint payment link

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
            self::MemberAwaitingPin,
        ], true);
    }
}
