<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PayoutBatchResource;
use App\Models\PayoutBatch;
use App\Services\Disbursement\PayoutReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutBatchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finance/Payouts/Index', [
            'activeModule' => 'finance-payouts',
            'batches'      => PayoutBatchResource::collection(
                PayoutBatch::query()->withCount('disbursements')->latest()->paginate(25)
            ),
        ]);
    }

    public function show(PayoutBatch $payout): Response
    {
        $payout->load('disbursements');

        return Inertia::render('Finance/Payouts/Show', [
            'activeModule' => 'finance-payouts',
            'batch'        => new PayoutBatchResource($payout),
            'rows'         => $payout->disbursements->map(fn ($d) => [
                'id' => $d->id, 'beneficiary_name' => $d->beneficiary_name,
                'beneficiary_account' => $this->maskAccountNumber((string) $d->beneficiary_account),
                'net_to_recipient' => $d->net_to_recipient, 'status' => $d->status->value,
                'channel' => $d->channel->value, 'failure_reason' => $d->failure_reason,
            ]),
        ]);
    }

    // Payout reviewers verify by name + amount, not the full account number —
    // mirrors the masking convention in OrgBankAccountResource (last 4 digits
    // visible, rest redacted) so beneficiary bank account PII isn't shipped
    // to the client unmasked.
    private function maskAccountNumber(string $accountNumber): string
    {
        return str_repeat('•', max(4, strlen($accountNumber) - 4)) . substr($accountNumber, -4);
    }

    public function release(Request $request, PayoutBatch $payout, PayoutReleaseService $releaser): RedirectResponse
    {
        $releaser->release($payout, $request->user());

        return back()->with('success', 'Payout batch released.');
    }
}
