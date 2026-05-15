<?php

namespace App\Http\Controllers;

use App\Http\Requests\Whistleblower\PostMessageRequest;
use App\Http\Requests\Whistleblower\SubmitReportRequest;
use App\Http\Requests\Whistleblower\TrackingLookupRequest;
use App\Http\Resources\TrackingStatusResource;
use App\Models\WhistleblowerReport;
use App\Services\Whistleblower\WhistleblowerSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, UN-authenticated whistleblower endpoints.
 *
 * Privacy guarantees:
 *  - Anonymous submission: no user_id, no IP recorded on the report row, the
 *    submitter_contact field is encrypted and only present if opt-in.
 *  - The tracking code is shown to the submitter ONCE on the confirmation
 *    screen; the server only stores its sha256 hash and cannot recover it.
 *  - Lookup is hash-based (timing-safe via hash_equals at the DB layer).
 */
class WhistleblowerPublicController extends Controller
{
    public function __construct(private readonly WhistleblowerSubmissionService $submissions) {}

    public function submitForm(): Response
    {
        return Inertia::render('Whistleblower/Submit', [
            'categories' => collect(\App\Enums\WhistleblowerCategory::cases())
                ->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    public function submit(SubmitReportRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $files   = $request->file('evidence') ?? [];

        $result = $this->submissions->submit(
            payload:           $payload,
            authenticatedUser: $request->user(),   // null in anonymous case
            files:             is_array($files) ? $files : [$files],
        );

        // Flash the tracking code through the session ONCE so the confirmation
        // page can display it. The code is never logged or persisted elsewhere.
        return redirect()
            ->route('whistleblower.confirmation')
            ->with([
                'wb_case_number'   => $result['report']->case_number,
                'wb_tracking_code' => $result['tracking_code'],
            ]);
    }

    public function confirmation(Request $request): Response
    {
        return Inertia::render('Whistleblower/Confirmation', [
            'case_number'   => $request->session()->get('wb_case_number'),
            'tracking_code' => $request->session()->get('wb_tracking_code'),
        ]);
    }

    public function trackForm(): Response
    {
        return Inertia::render('Whistleblower/Track');
    }

    public function track(TrackingLookupRequest $request)
    {
        $report = WhistleblowerReport::findByTrackingCode((string) $request->validated('tracking_code'));

        if (! $report) {
            return back()->withErrors(['tracking_code' => 'No case found for that tracking code.'])->onlyInput();
        }

        $report->load('messages');

        return Inertia::render('Whistleblower/Status', [
            'status'        => new TrackingStatusResource($report),
            'tracking_code' => $request->validated('tracking_code'),
        ]);
    }

    /**
     * Submitter-side reply. Authenticates by knowing the tracking code; the
     * code is re-supplied in the request body so the user never has to stay
     * authenticated via session.
     */
    public function reply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'min:8', 'max:32'],
            'body'          => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        $report = WhistleblowerReport::findByTrackingCode($data['tracking_code']);
        if (! $report) {
            return back()->withErrors(['tracking_code' => 'No case found for that tracking code.'])->withInput();
        }

        $this->submissions->postSubmitterMessage($report, $data['body']);

        return redirect()->route('whistleblower.track')->with('success', 'Your message has been posted to the investigator.');
    }
}
