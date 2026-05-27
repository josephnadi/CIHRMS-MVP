<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\ArInvoiceStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\User;
use App\Services\Finance\PaymentIntentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeesController extends Controller
{
    public function __construct(private readonly PaymentIntentService $intents) {}

    public function index(Request $request): Response
    {
        $member = $request->user('member');

        $invoices = ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('Portal/Fees/Index', [
            'invoices' => $invoices->through(fn ($i) => [
                'id'              => $i->id,
                'reference'       => $i->reference,
                'invoice_date'    => $i->invoice_date?->toDateString(),
                'due_date'        => $i->due_date?->toDateString(),
                'total'           => (float) $i->total,
                'amount_received' => (float) $i->amount_received,
                'outstanding'     => (float) ($i->total - $i->amount_received),
                'currency'        => $i->currency,
                'status'          => is_object($i->status) ? $i->status->value : (string) $i->status,
                'notes'           => $i->notes,
            ]),
        ]);
    }

    public function pay(Request $request, ArInvoice $invoice): RedirectResponse
    {
        $member = $request->user('member');

        // IDOR guard: the invoice must belong to this member's customer.
        abort_unless($invoice->customer_id === $member->customer_id, 403);

        if (! in_array($invoice->status, [ArInvoiceStatus::Approved, ArInvoiceStatus::PartiallyPaid], true)) {
            return back()->withErrors([
                'status' => 'This invoice is not in a payable state.',
            ]);
        }

        try {
            $intent = $this->intents->createForInvoice(
                invoice: $invoice,
                amount:  (float) ($invoice->total - $invoice->amount_received),
                creator: $this->resolveSystemUser(),
                callbackUrl: route('portal.home'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->away($intent->authorization_url);
    }

    /**
     * The portal pay flow is initiated by a Member (not a User), but
     * `PaymentIntent.created_by` is a required FK to `users`. We resolve
     * a "billing-system" user — configurable via `services.billing.system_user_id`
     * with a fallback to the first super_admin. Fails loudly if neither is
     * available rather than silently no-op-ing the audit trail.
     */
    private function resolveSystemUser(): User
    {
        $configured = (int) config('services.billing.system_user_id', 0);
        if ($configured > 0) {
            $u = User::find($configured);
            if ($u) return $u;
        }

        $u = User::where('role', UserRole::SuperAdmin->value)->orderBy('id')->first();
        if (! $u) {
            throw new DomainException('Portal pay-flow requires a super_admin User to own the PaymentIntent. Seed one and try again.');
        }
        return $u;
    }
}
