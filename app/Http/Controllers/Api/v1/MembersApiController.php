<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ArInvoiceStatus;
use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\MemberResource;
use App\Models\ArInvoice;
use App\Models\Member;
use App\Models\User;
use App\Services\Finance\PaymentIntentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public API v1 — Members + their invoices + payment-link minting.
 *
 * Scopes (enforced via the `api.scope:<ability>` middleware on the
 * route group):
 *   - `members:read`   — list / show
 *   - `invoices:read`  — list invoices for a member
 *   - `gateway:create` — mint a Paystack payment link for a specific
 *                        invoice (re-using the existing F4 scope)
 *
 * Returns Inertia-free JSON resources so partner systems can
 * synchronise member directories and outstanding-fee snapshots without
 * scraping the staff UI.
 */
class MembersApiController extends Controller
{
    public function __construct(private readonly PaymentIntentService $intents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Member::query()
            ->when(
                $request->filled('class'),
                fn ($qq) => $qq->where('class', $request->string('class')->lower()->value()),
            )
            ->when(
                $request->filled('status'),
                fn ($qq) => $qq->where('status', $request->string('status')->lower()->value()),
            )
            ->when(
                $request->filled('q'),
                fn ($qq) => $qq->where(function ($qqq) use ($request) {
                    $term = $request->string('q')->value();
                    $qqq->where('member_no', 'like', "%{$term}%")
                        ->orWhere('name',  'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                }),
            )
            ->orderBy('id');

        return MemberResource::collection(
            $q->paginate((int) min((int) $request->input('per_page', 25), 100))
        );
    }

    public function show(Member $member): MemberResource
    {
        return new MemberResource($member);
    }

    /**
     * List invoices for a specific member. Returns a thin projection
     * (not the full ArInvoiceResource the admin UI uses) so partners
     * don't accidentally couple to internal AR fields they don't need.
     */
    public function invoices(Request $request, Member $member): JsonResponse
    {
        $onlyOpen = $request->boolean('open');

        $q = ArInvoice::query()
            ->where('customer_id', $member->customer_id)
            ->orderByDesc('invoice_date');

        if ($onlyOpen) {
            $q->whereIn('status', [
                ArInvoiceStatus::Approved->value,
                ArInvoiceStatus::PartiallyPaid->value,
            ]);
        }

        $rows = $q->limit(100)->get(['id', 'reference', 'invoice_date', 'due_date',
            'total', 'amount_received', 'currency', 'status']);

        return response()->json([
            'data' => $rows->map(fn ($i) => [
                'id'              => $i->id,
                'reference'       => $i->reference,
                'invoice_date'    => optional($i->invoice_date)->toDateString(),
                'due_date'        => optional($i->due_date)?->toDateString(),
                'total'           => (float) $i->total,
                'amount_received' => (float) $i->amount_received,
                'outstanding'     => (float) ($i->total - $i->amount_received),
                'currency'        => $i->currency,
                'status'          => is_object($i->status) ? $i->status->value : (string) $i->status,
            ])->values(),
            'meta' => [
                'member_id' => $member->id,
                'count'     => $rows->count(),
                'filter'    => $onlyOpen ? 'open' : 'all',
            ],
        ]);
    }

    /**
     * Mint a Paystack payment link for a specific open invoice owned by
     * the given member. Returns the authorization_url; the partner can
     * forward this to the member (SMS / email / their own portal) and
     * Paystack settles back through the existing webhook.
     */
    public function paymentIntent(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'ar_invoice_id' => ['required', 'integer', 'exists:ar_invoices,id'],
            'amount'        => ['sometimes', 'numeric', 'min:0.01', 'max:9999999.99'],
        ]);

        $invoice = ArInvoice::findOrFail((int) $data['ar_invoice_id']);
        abort_unless($invoice->customer_id === $member->customer_id, 403);

        $amount = (float) ($data['amount'] ?? ($invoice->total - $invoice->amount_received));

        try {
            $intent = $this->intents->createForInvoice(
                invoice:     $invoice,
                amount:      $amount,
                creator:     $this->resolveSystemUser(),
                callbackUrl: null,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'reference'         => $intent->reference,
                'authorization_url' => $intent->authorization_url,
                'amount'            => (float) $intent->amount,
                'currency'          => $intent->currency,
                'expires_at'        => $intent->expires_at?->toIso8601String(),
            ],
        ], 201);
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
            throw new DomainException('Partner API pay-flow requires a super_admin User to own the PaymentIntent.');
        }
        return $u;
    }
}
