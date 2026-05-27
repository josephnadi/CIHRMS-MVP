<?php

declare(strict_types=1);

namespace App\Services\Messaging\Ussd;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArReceiptStatus;
use App\Enums\UserRole;
use App\Enums\UssdState;
use App\Models\ArInvoice;
use App\Models\ArReceipt;
use App\Models\Member;
use App\Models\MemberPhonePin;
use App\Models\UssdSession;
use App\Models\User;
use App\Services\Finance\PaymentIntentService;
use App\Services\Messaging\Sms\SmsDispatcher;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * USSD menu for CIHRM members. Branches off the main `UssdSessionHandler`
 * when the welcome screen receives input `2` (member fees). The member
 * is identified by their phone number (msisdn) — there's no member-no
 * entry step, just a PIN prompt.
 *
 * Menu tree:
 *
 *   MemberAwaitingPin ─ PIN OK ─► MemberMainMenu
 *      1 = Check my fees           (outstanding total + SMS detail)
 *      2 = Pay a fee               → MemberFeeSelect (lists payable)
 *      3 = My last receipt
 *      4 = Exit
 *
 *   MemberFeeSelect ─ N ─► MemberFeeConfirm (single invoice)
 *   MemberFeeConfirm
 *      1 = Yes → create PaymentIntent + SMS Paystack link
 *      2 = No  → cancel
 *
 * The pay flow calls the same `PaymentIntentService::createForInvoice()`
 * the portal uses, so the customer ends up at Paystack's hosted checkout
 * via an SMS link rather than a redirect.
 */
class MemberFeesHandler
{
    public function __construct(
        private readonly PaymentIntentService $intents,
        private readonly SmsDispatcher $sms,
    ) {}

    /**
     * Entry point — called from `UssdSessionHandler::onWelcome` when
     * the user picks option 2. Tries to look the member up by phone
     * before showing the PIN prompt.
     */
    public function enterMemberFlow(UssdSession $session): string
    {
        $member = Member::query()->where('phone', $session->phone)->first();
        if ($member === null) {
            return $this->end('No member account is linked to this phone number.');
        }
        if (is_object($member->status) && $member->status->value !== 'active') {
            return $this->end('Your member account is not active. Contact the institute.');
        }

        $session->update([
            'state'   => UssdState::MemberAwaitingPin->value,
            'context' => array_merge($session->context ?? [], ['member_id' => $member->id]),
        ]);

        return $this->cont('CIHRM member fees.' . "\n" . 'Enter your 4-digit PIN:');
    }

    /**
     * Dispatch the next step based on the current state. Called from
     * `UssdSessionHandler::dispatch()` for any of the `Member*` states.
     */
    public function dispatch(UssdSession $session, string $input): string
    {
        $state = $session->state;

        return match ($state) {
            UssdState::MemberAwaitingPin => $this->onPin($session, $input),
            UssdState::MemberMainMenu    => $this->onMainMenu($session, $input),
            UssdState::MemberFeeSelect   => $this->onFeeSelect($session, $input),
            UssdState::MemberFeeConfirm  => $this->onFeeConfirm($session, $input),
            default                       => $this->end('Service unavailable. Dial again.'),
        };
    }

    // ── PIN auth ─────────────────────────────────────────────────────

    private function onPin(UssdSession $session, string $input): string
    {
        $memberId = (int) ($session->context['member_id'] ?? 0);
        $member   = $memberId > 0 ? Member::find($memberId) : null;
        if (! $member) return $this->end('Session corrupted.');

        $pin = MemberPhonePin::where('member_id', $member->id)->first();
        if (! $pin) {
            return $this->end('No PIN set. Contact the institute to enrol.');
        }
        if ($pin->isLocked()) {
            return $this->end('PIN locked. Try again in 15 minutes.');
        }
        if (! $pin->verify($input)) {
            $pin->recordFailure(
                maxAttempts: (int) config('messaging.ussd.pin_max_attempts', 5),
                lockMinutes: (int) config('messaging.ussd.pin_lock_minutes', 15),
            );
            return $this->end('Wrong PIN.');
        }
        $pin->recordSuccess();

        $session->update(['state' => UssdState::MemberMainMenu->value]);
        return $this->cont($this->mainMenuText($member));
    }

    private function mainMenuText(Member $member): string
    {
        return "Hello, {$member->name}.\n"
            . "1. My outstanding fees\n"
            . "2. Pay a fee\n"
            . "3. My last receipt\n"
            . "4. Exit";
    }

    // ── Main menu dispatch ───────────────────────────────────────────

    private function onMainMenu(UssdSession $session, string $input): string
    {
        return match ($input) {
            '1' => $this->showOutstanding($session),
            '2' => $this->beginFeeSelect($session),
            '3' => $this->showLastReceipt($session),
            '4' => $this->end('Goodbye.'),
            default => $this->end('Invalid option.'),
        };
    }

    private function showOutstanding(UssdSession $session): string
    {
        $member = $this->memberOf($session);
        if (! $member) return $this->end('Session corrupted.');

        $totalDue = (float) ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->whereIn('status', [ArInvoiceStatus::Approved->value, ArInvoiceStatus::PartiallyPaid->value])
            ->sum(\DB::raw('total - amount_received'));

        // SMS the breakdown so the member has a written record.
        $this->safeSms(
            $member->phone,
            "CIHRM: outstanding GHS " . number_format($totalDue, 2) . ". Dial again to pay.",
            contextId: $member->id,
        );

        return $this->end("Outstanding: GHS " . number_format($totalDue, 2) . ". Details sent by SMS.");
    }

    // ── Pay flow ─────────────────────────────────────────────────────

    private function beginFeeSelect(UssdSession $session): string
    {
        $member = $this->memberOf($session);
        if (! $member) return $this->end('Session corrupted.');

        $invoices = ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->whereIn('status', [ArInvoiceStatus::Approved->value, ArInvoiceStatus::PartiallyPaid->value])
            ->orderBy('invoice_date')
            ->limit(5)
            ->get(['id', 'reference', 'total', 'amount_received', 'currency']);

        if ($invoices->isEmpty()) {
            return $this->end('No outstanding fees on your account.');
        }

        $lines  = ["Select fee to pay:"];
        $offers = [];
        foreach ($invoices->values() as $i => $inv) {
            $idx = $i + 1;
            $outstanding = (float) ($inv->total - $inv->amount_received);
            $lines[] = "{$idx}. {$inv->reference} — {$inv->currency} " . number_format($outstanding, 2);
            $offers["{$idx}"] = [
                'invoice_id'  => $inv->id,
                'amount'      => round($outstanding, 2),
                'reference'   => $inv->reference,
            ];
        }

        $session->update([
            'state'   => UssdState::MemberFeeSelect->value,
            'context' => array_merge($session->context ?? [], ['offers' => $offers]),
        ]);

        return $this->cont(implode("\n", $lines));
    }

    private function onFeeSelect(UssdSession $session, string $input): string
    {
        $offers = $session->context['offers'] ?? [];
        $offer  = $offers[$input] ?? null;
        if ($offer === null) {
            return $this->end('Invalid selection.');
        }

        $session->update([
            'state'   => UssdState::MemberFeeConfirm->value,
            'context' => array_merge($session->context ?? [], ['selected' => $offer]),
        ]);

        return $this->cont(
            "Pay GHS " . number_format((float) $offer['amount'], 2) . " for {$offer['reference']}?\n"
            . "1. Yes\n"
            . "2. No"
        );
    }

    private function onFeeConfirm(UssdSession $session, string $input): string
    {
        if ($input !== '1') {
            return $this->end('Cancelled.');
        }

        $member   = $this->memberOf($session);
        $selected = $session->context['selected'] ?? null;
        if (! $member || ! $selected) return $this->end('Session corrupted.');

        $invoice = ArInvoice::find((int) $selected['invoice_id']);
        if (! $invoice || $invoice->customer_id !== $member->customer_id) {
            return $this->end('Invoice no longer available.');
        }

        try {
            $intent = $this->intents->createForInvoice(
                invoice: $invoice,
                amount:  (float) $selected['amount'],
                creator: $this->resolveSystemUser(),
                callbackUrl: null,
            );
        } catch (DomainException $e) {
            return $this->end('Unable to start payment: ' . $e->getMessage());
        }

        // SMS the Paystack link so the member can finish payment on a card or
        // bank/momo channel without typing it inside the USSD session window.
        $this->safeSms(
            $member->phone,
            "CIHRM: pay GHS " . number_format((float) $selected['amount'], 2)
                . " for {$invoice->reference} here: {$intent->authorization_url}",
            contextId: $intent->id,
        );

        return $this->end('Payment link sent by SMS.');
    }

    // ── Quick utilities ──────────────────────────────────────────────

    private function showLastReceipt(UssdSession $session): string
    {
        $member = $this->memberOf($session);
        if (! $member) return $this->end('Session corrupted.');

        $r = ArReceipt::query()
            ->where('customer_id', $member->customer_id)
            ->where('status', ArReceiptStatus::Processed->value)
            ->latest('receipt_date')
            ->first();

        if (! $r) {
            return $this->end('No processed receipts on file.');
        }

        return $this->end(
            "Last receipt: {$r->reference}\n"
            . "GHS " . number_format((float) $r->amount, 2) . " on " . optional($r->receipt_date)->toDateString()
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function memberOf(UssdSession $session): ?Member
    {
        $id = (int) ($session->context['member_id'] ?? 0);
        return $id > 0 ? Member::find($id) : null;
    }

    private function resolveSystemUser(): User
    {
        $configured = (int) config('services.billing.system_user_id', 0);
        if ($configured > 0) {
            $u = User::find($configured);
            if ($u) return $u;
        }
        $u = User::where('role', UserRole::SuperAdmin->value)->orderBy('id')->first();
        if (! $u) {
            throw new DomainException('USSD pay-flow requires a super_admin User to own the PaymentIntent.');
        }
        return $u;
    }

    private function safeSms(string $to, string $body, ?int $contextId = null): void
    {
        try {
            $this->sms->send(
                toPhone:     $to,
                body:        $body,
                contextType: 'ussd_member_fees',
                contextId:   $contextId,
            );
        } catch (\Throwable $e) {
            Log::warning('[billing] USSD-bound SMS failed', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cont(string $body): string { return "CON {$body}"; }
    private function end(string $body): string  { return "END {$body}"; }
}
