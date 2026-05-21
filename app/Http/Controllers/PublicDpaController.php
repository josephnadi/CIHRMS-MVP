<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Http\Requests\Privacy\SubmitPublicDpaRequest;
use App\Models\DataSubjectRequest;
use App\Notifications\DpaVerificationLink;
use App\Services\Privacy\DataSubjectRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public Data Protection Act 2012 (Act 843) portal.
 *
 * Routes (all unauthenticated, throttled):
 *   GET  /dpa            — show the submission form
 *   POST /dpa            — submit + email verification link
 *   GET  /dpa/verify     — click target from the emailed link
 *   GET  /dpa/track      — track-by-reference page
 *   POST /dpa/track      — look up a single request by reference + email
 *
 * Public submissions stay invisible to the DPO queue until the subject
 * clicks the emailed verification link, at which point the status flips
 * to `submitted` and the Act-843 30-day clock starts.
 */
class PublicDpaController extends Controller
{
    public function __construct(private readonly DataSubjectRequestService $service) {}

    public function form(): Response
    {
        return Inertia::render('Dpa/Submit', [
            'types' => collect(DataSubjectRequestType::cases())->map(fn ($t) => [
                'value'           => $t->value,
                'label'           => $t->label(),
                'produces_export' => $t->producesExport(),
                'is_mutating'     => $t->isMutating(),
            ]),
        ]);
    }

    public function submit(SubmitPublicDpaRequest $request): RedirectResponse
    {
        $req = $this->service->submitPublic(
            email:               (string) $request->validated('subject_email'),
            fullName:            (string) $request->validated('subject_full_name'),
            type:                DataSubjectRequestType::from((string) $request->validated('request_type')),
            statement:           (string) $request->validated('subject_statement'),
            rectificationDetails: $request->validated('rectification_details'),
            objectionPurpose:    $request->validated('objection_purpose'),
        );

        Notification::route('mail', $req->subject_email)
            ->notify(new DpaVerificationLink($req));

        return redirect()->route('dpa.confirmation', ['reference' => $req->reference]);
    }

    public function confirmation(Request $request): Response
    {
        return Inertia::render('Dpa/Confirmation', [
            'reference' => (string) $request->query('reference', ''),
        ]);
    }

    public function verify(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $req   = $token ? $this->service->verifyPublic($token) : null;

        return Inertia::render('Dpa/Verified', [
            'ok'        => $req !== null,
            'reference' => $req?->reference,
        ]);
    }

    public function trackForm(): Response
    {
        return Inertia::render('Dpa/Track', [
            'result' => null,
        ]);
    }

    /**
     * Look up a single request. Requires BOTH the reference *and* the email
     * the subject used — neither alone identifies the request. We never
     * leak whether a reference exists when the email doesn't match.
     */
    public function track(Request $request): Response
    {
        $data = $request->validate([
            'reference'     => ['required', 'string', 'max:32'],
            'subject_email' => ['required', 'email', 'max:255'],
        ]);

        $req = DataSubjectRequest::query()
            ->where('reference', $data['reference'])
            ->where(function ($q) use ($data) {
                $q->where('subject_email', strtolower(trim($data['subject_email'])))
                  // Also match authenticated submissions where the linked
                  // User's email matches — same subject, just logged in once.
                  ->orWhereHas('subject', fn ($u) => $u->where('email', strtolower(trim($data['subject_email']))));
            })
            ->first();

        return Inertia::render('Dpa/Track', [
            'result' => $req ? [
                'reference'              => $req->reference,
                'status'                 => $req->status->value,
                'status_label'           => $req->status->label(),
                'request_type'           => $req->request_type->value,
                'submitted_at'           => $req->submitted_at?->toIso8601String(),
                'target_completion_date' => $req->target_completion_date?->toDateString(),
                'completed_at'           => $req->completed_at?->toIso8601String(),
                'decision_summary'       => $req->decision_summary,
                // Crucially: NEVER expose verification_token. Hidden on the model.
            ] : ['not_found' => true],
        ]);
    }
}
