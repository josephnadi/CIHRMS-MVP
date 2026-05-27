<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreMemberRequest;
use App\Http\Requests\Billing\UpdateMemberRequest;
use App\Http\Resources\Billing\MemberResource;
use App\Models\Member;
use App\Services\Billing\MemberRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(private readonly MemberRegistrationService $registration) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Member::class);

        $filters = $request->only(['q', 'class', 'status']);

        $members = Member::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($qq) use ($term) {
                    $qq->where('member_no', 'like', "%{$term}%")
                       ->orWhere('name',  'like', "%{$term}%")
                       ->orWhere('email', 'like', "%{$term}%")
                       ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->when($filters['class']  ?? null, fn ($q, $v) => $q->where('class', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Billing/Members/Index', [
            'activeModule' => 'billing-members',
            'members'      => MemberResource::collection($members),
            'filters'      => $filters,
        ]);
    }

    public function show(Member $member): Response
    {
        $this->authorize('view', $member);
        $member->load(['customer', 'assignments.feeProduct', 'assignments.arInvoice']);

        return Inertia::render('Billing/Members/Show', [
            'activeModule' => 'billing-members',
            'member'       => (new MemberResource($member))->resolve(),
            'assignments'  => $member->assignments->map(fn ($a) => [
                'id'             => $a->id,
                'period_label'   => $a->period_label,
                'status'         => is_object($a->status) ? $a->status->value : $a->status,
                'fee_product'    => [
                    'code' => $a->feeProduct?->code,
                    'name' => $a->feeProduct?->name,
                ],
                'ar_invoice_ref' => $a->arInvoice?->reference,
                'due_date'       => $a->due_date?->toDateString(),
            ])->values(),
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = $this->registration->register(
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('billing.members.show', $member)
            ->with('success', "Member {$member->member_no} registered.");
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->fill($request->validated())->save();
        return back()->with('success', 'Member updated.');
    }

    public function destroy(Member $member, Request $request): RedirectResponse
    {
        $this->authorize('delete', $member);
        $member->delete();
        return redirect()->route('billing.members.index')
            ->with('success', "Member {$member->member_no} removed.");
    }
}
