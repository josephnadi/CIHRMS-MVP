<?php

namespace App\Http\Controllers;

use App\Models\WebhookSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WebhookSubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('api.webhooks_manage'), 403);

        $subs = WebhookSubscription::with('creator:id,name')->latest()->paginate(20);

        return Inertia::render('Settings/Webhooks/Index', [
            'subscriptions' => $subs,
            'activeModule'  => 'webhooks',
            'flash_secret'  => $request->session()->get('flash_secret'),
            'available_events' => [
                'payroll.run.approved',
                'payroll.run.paid',
                'payroll.run.reversed',
                'identity.verified',
                'identity.duplicate_detected',
                'loan.disbursed',
                'loan.fully_repaid',
                'offboarding.completed',
                'whistleblower.case_closed',
                'data_subject.fulfilled',
                '*',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('api.webhooks_manage'), 403);

        $data = $request->validate([
            'partner_name'         => ['required', 'string', 'max:120'],
            'callback_url'         => ['required', 'url', 'max:1024'],
            'subscribed_events'    => ['required', 'array', 'min:1'],
            'subscribed_events.*'  => ['string', 'max:120'],
        ]);

        $secret = (string) Str::random(48);

        WebhookSubscription::create([
            'partner_name'      => $data['partner_name'],
            'callback_url'      => $data['callback_url'],
            'secret'            => $secret,
            'subscribed_events' => $data['subscribed_events'],
            'is_active'         => true,
            'created_by'        => $request->user()->id,
        ]);

        return back()->with([
            'flash_secret' => $secret,
            'success'      => 'Webhook subscription created. Copy the secret now — it cannot be retrieved again.',
        ]);
    }

    public function update(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('api.webhooks_manage'), 403);

        $data = $request->validate([
            'is_active'           => ['required', 'boolean'],
            'subscribed_events'   => ['nullable', 'array'],
            'subscribed_events.*' => ['string'],
        ]);

        $subscription->update(array_filter([
            'is_active'         => $data['is_active'],
            'subscribed_events' => $data['subscribed_events'] ?? null,
            'failure_count'     => $data['is_active'] ? 0 : $subscription->failure_count,
        ], fn ($v) => $v !== null));

        return back()->with('success', 'Subscription updated.');
    }

    public function destroy(Request $request, WebhookSubscription $subscription): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('api.webhooks_manage'), 403);
        $subscription->delete();
        return back()->with('success', 'Subscription deleted.');
    }
}
