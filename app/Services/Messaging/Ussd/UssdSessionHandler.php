<?php

namespace App\Services\Messaging\Ussd;

use App\Enums\AttendanceSource;
use App\Enums\UssdState;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\PayrollLine;
use App\Models\StaffPhonePin;
use App\Models\UssdSession;
use App\Models\WhistleblowerReport;
use App\Services\Attendance\AttendanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * USSD session state-machine. Drives the menu interactions exposed at
 * Ghana's shared *920*HR# (or whatever shortcode the config specifies).
 *
 * Menu tree:
 *
 *   1. WELCOME → enter staff id
 *   2. AwaitingStaffId → enter PIN
 *   3. AwaitingPin → Authenticated → main menu:
 *        1) View latest payslip
 *        2) Leave balance
 *        3) Clock in
 *        4) Clock out
 *      0)  Track a whistleblower case (separate, unauthenticated path)
 *
 * Provider-agnostic. The webhook controller (HubtelUssdController) converts
 * provider-shaped requests into the standard {sessionId, msisdn, text}
 * tuple and calls `handle()`. Output strings already include the prefix
 * Hubtel/MTN/AT expect (`CON ` for "continue", `END ` for "terminate").
 */
class UssdSessionHandler
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Handle a single USSD step. `text` is the cumulative path Hubtel uses —
     * e.g. user pressed "1" then "2" then "5", text = "1*2*5". We slice off
     * the LAST segment as the current input.
     */
    public function handle(string $sessionId, string $phone, string $shortcode, string $text): string
    {
        $session = $this->resolveSession($sessionId, $phone, $shortcode);

        if ($session->isExpired()) {
            $session->update(['state' => UssdState::Terminated->value]);
            return $this->end('Session expired. Dial again.');
        }

        $input = $this->lastSegment($text);
        $session->update(['last_input' => $input]);

        $response = $this->dispatch($session, $input);
        $session->update(['last_response' => $response]);

        return $response;
    }

    private function dispatch(UssdSession $session, string $input): string
    {
        $state = $session->state;

        // Whistleblower lookup is reachable from the welcome screen without auth.
        // Reserved input "0" on the welcome screen jumps to it.
        if ($state === UssdState::Welcome && $input === '0') {
            $session->update(['state' => UssdState::WhistleblowerCode->value]);
            return $this->cont('Enter your whistleblower tracking code:');
        }

        return match (true) {
            $state === UssdState::Welcome           => $this->onWelcome($session, $input),
            $state === UssdState::AwaitingStaffId   => $this->onStaffId($session, $input),
            $state === UssdState::AwaitingPin       => $this->onPin($session, $input),
            $state === UssdState::Authenticated     => $this->onMainMenu($session, $input),
            $state === UssdState::PayslipMenu       => $this->renderPayslip($session),
            $state === UssdState::LeaveBalance      => $this->renderLeaveBalance($session),
            $state === UssdState::ClockMenu         => $this->onClockMenu($session, $input),
            $state === UssdState::WhistleblowerCode => $this->onWhistleblowerCode($session, $input),
            default                                  => $this->end('Service unavailable. Try later.'),
        };
    }

    private function onWelcome(UssdSession $session, string $input): string
    {
        // First display — no input yet (text is empty). Otherwise treat anything
        // they entered as the staff id immediately.
        if ($input === '') {
            return $this->cont(
                "CIHRMS Self-Service\n"
                . "Enter your Staff ID:\n"
                . "(or enter 0 to track a whistleblower case)"
            );
        }
        return $this->onStaffId($session, $input);
    }

    private function onStaffId(UssdSession $session, string $input): string
    {
        $employee = Employee::where('employee_no', $input)->first();
        if (! $employee) {
            return $this->end('Unknown Staff ID. Dial again.');
        }
        $session->update([
            'employee_id' => $employee->id,
            'state'       => UssdState::AwaitingPin->value,
        ]);
        $session->pushContext('staff_id', $input);

        return $this->cont('Enter your 4-digit PIN:');
    }

    private function onPin(UssdSession $session, string $input): string
    {
        $employee = $session->employee;
        if (! $employee) return $this->end('Session corrupted.');

        $pin = StaffPhonePin::where('employee_id', $employee->id)->first();
        if (! $pin) {
            return $this->end('No PIN set up. Visit HR to enrol.');
        }
        if ($pin->isLocked()) {
            $session->update(['state' => UssdState::PinLocked->value]);
            return $this->end('PIN locked. Try again later.');
        }
        if (! $pin->verify($input)) {
            $pin->recordFailure(
                maxAttempts: (int) config('messaging.ussd.pin_max_attempts', 5),
                lockMinutes: (int) config('messaging.ussd.pin_lock_minutes', 15),
            );
            return $this->end('Wrong PIN.');
        }

        $pin->recordSuccess();
        $session->update(['state' => UssdState::Authenticated->value]);

        return $this->cont($this->mainMenu($employee));
    }

    private function onMainMenu(UssdSession $session, string $input): string
    {
        return match ($input) {
            '1' => $this->showPayslip($session),
            '2' => $this->showLeaveBalance($session),
            '3' => $this->clockEvent($session, 'in'),
            '4' => $this->clockEvent($session, 'out'),
            default => $this->end('Invalid option.'),
        };
    }

    private function showPayslip(UssdSession $session): string
    {
        $line = PayrollLine::query()
            ->where('employee_id', $session->employee_id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->latest('id')
            ->first();

        if (! $line) {
            return $this->end('No payslip available yet.');
        }

        $run = $line->run;
        $msg = sprintf(
            "Payslip %s\nGross: GHS %s\nPAYE: GHS %s\nSSNIT: GHS %s\nNet: GHS %s",
            $run->periodLabel(),
            number_format((float) $line->gross, 2),
            number_format((float) $line->paye, 2),
            number_format((float) $line->ssnit_tier1_employee, 2),
            number_format((float) $line->net, 2),
        );

        return $this->end($msg);
    }

    private function showLeaveBalance(UssdSession $session): string
    {
        $balances = LeaveBalance::where('employee_id', $session->employee_id)
            ->where('year', now()->year)
            ->get();

        if ($balances->isEmpty()) {
            return $this->end('No leave balance found for ' . now()->year . '.');
        }

        $lines = ["Leave " . now()->year];
        foreach ($balances as $b) {
            $remaining = max(0, (float) $b->total_days - (float) $b->used_days);
            $lines[] = sprintf('%s: %s left', ucfirst((string) $b->type), $remaining);
        }
        return $this->end(implode("\n", $lines));
    }

    private function clockEvent(UssdSession $session, string $direction): string
    {
        $employee = $session->employee;
        if (! $employee) return $this->end('Session corrupted.');

        try {
            $this->attendance->record(
                employee:   $employee,
                eventAt:    now(),
                direction:  $direction,
                source:     AttendanceSource::Webhook,    // USSD = generic webhook source
                reason:     'USSD self-service',
            );
        } catch (\Throwable $e) {
            return $this->end('Could not record. Try later.');
        }

        $label = $direction === 'in' ? 'in' : 'out';
        return $this->end("Clock-{$label} recorded at " . now()->format('H:i'));
    }

    private function onClockMenu(UssdSession $session, string $input): string
    {
        return match ($input) {
            '1' => $this->clockEvent($session, 'in'),
            '2' => $this->clockEvent($session, 'out'),
            default => $this->end('Invalid option.'),
        };
    }

    private function onWhistleblowerCode(UssdSession $session, string $input): string
    {
        if (strlen($input) < 8) {
            return $this->end('Invalid code.');
        }
        $report = WhistleblowerReport::findByTrackingCode($input);
        if (! $report) {
            return $this->end('No case found for that code.');
        }

        $session->update(['state' => UssdState::WhistleblowerStatus->value]);
        return $this->end(sprintf(
            "Case %s\nStatus: %s\nSubmitted: %s",
            $report->case_number,
            $report->status?->label() ?? '—',
            optional($report->received_at)->toDateString() ?? '—',
        ));
    }

    private function mainMenu(Employee $employee): string
    {
        $name = $employee->user?->name ?? $employee->employee_no;
        return "Hi {$name}\n1. Latest payslip\n2. Leave balance\n3. Clock in\n4. Clock out";
    }

    private function resolveSession(string $sessionId, string $phone, string $shortcode): UssdSession
    {
        return UssdSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'phone'      => $phone,
                'shortcode'  => $shortcode,
                'state'      => UssdState::Welcome->value,
                'context'    => [],
                'expires_at' => now()->addSeconds((int) config('messaging.ussd.session_ttl_seconds', 180)),
            ],
        );
    }

    private function lastSegment(string $text): string
    {
        if ($text === '') return '';
        $parts = explode('*', $text);
        return trim(end($parts));
    }

    private function cont(string $body): string
    {
        return "CON {$body}";
    }

    private function end(string $body): string
    {
        return "END {$body}";
    }

    /**
     * Convenience for `renderPayslip` and `renderLeaveBalance` exit states
     * after the user has just been shown the data.
     */
    private function renderPayslip(UssdSession $session): string  { return $this->showPayslip($session); }
    private function renderLeaveBalance(UssdSession $session): string { return $this->showLeaveBalance($session); }
}
